<?php

namespace App\Services\Reparaciones;

use App\Models\AccesoCliente;
use App\Models\ActualizacionReparacion;
use App\Models\Cotizacion;
use App\Models\EventoCotizacion;
use App\Models\Evidencia;
use App\Models\HistorialEstado;
use App\Models\OrdenServicio;
use App\Models\TrabajoReparacion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Registra avances, cambios de estado de los trabajos, publicaciones al cliente, revisión final y las etapas
 * generales de la orden. Cada operación valida la pertenencia a la orden dentro de una transacción con bloqueo.
 */
class RepairProgressService
{
    public const MAX_PHOTOS = 10;

    /**
     * Transiciones manuales de la orden desde el seguimiento. Ninguna se dispara por actualizar un trabajo.
     *
     * @var array<string, list<string>>
     */
    public const ORDER_TRANSITIONS = [
        'esperando_aprobacion' => ['en_diagnostico'],
        'en_reparacion' => ['en_diagnostico', 'esperando_aprobacion', 'esperando_refaccion', 'esperando_refacciones', 'lista_entrega'],
        'esperando_refacciones' => ['en_reparacion'],
        'lista_entrega' => ['en_reparacion', 'esperando_refaccion', 'esperando_refacciones'],
    ];

    public function __construct(private RepairWorkService $works) {}

    /**
     * Registra un avance (con o sin cambio de estado) o una corrección de una publicación anterior.
     *
     * @param  array{id_trabajo: int|string, estado?: ?string, tipo?: ?string, id_actualizacion_corregida?: int|string|null, titulo: string, descripcion?: ?string, motivo?: ?string, visibilidad: string}  $data
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws ValidationException
     */
    public function recordUpdate(OrdenServicio $orden, User $user, array $data, array $photos): ActualizacionReparacion
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($orden, $user, $data, $photos, &$storedPaths): ActualizacionReparacion {
                $lockedOrder = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id_orden);
                $this->assertAcceptsUpdates($lockedOrder);

                $work = TrabajoReparacion::query()
                    ->where('id_orden', $lockedOrder->id_orden)
                    ->whereKey((int) $data['id_trabajo'])
                    ->lockForUpdate()
                    ->first();
                if ($work === null || ! Cotizacion::query()->whereKey($work->id_cotizacion)->whereIn('estado', Cotizacion::AUTHORIZED_STATUSES)->exists()) {
                    throw ValidationException::withMessages(['id_trabajo' => 'Selecciona un trabajo autorizado de esta orden.']);
                }

                $previousStatus = $work->estado;
                $newStatus = ($data['estado'] ?? null) ?: null;
                if ($newStatus === $previousStatus) {
                    $newStatus = null;
                }
                $motivo = trim((string) ($data['motivo'] ?? '')) ?: null;
                $type = ($data['tipo'] ?? null) === 'correccion' ? 'correccion' : 'avance';

                if ($newStatus !== null) {
                    if ($lockedOrder->estado === 'lista_entrega') {
                        throw ValidationException::withMessages(['estado' => 'La orden está lista para entrega. Reanuda la reparación antes de cambiar el estado de un trabajo.']);
                    }
                    if (! $work->canTransitionTo($newStatus)) {
                        throw ValidationException::withMessages(['estado' => 'Un trabajo «'.$work->statusLabel().'» no puede pasar a «'.(TrabajoReparacion::STATUSES[$newStatus]['label'] ?? $newStatus).'».']);
                    }
                    if ($motivo === null && in_array($newStatus, ['en_espera', 'no_realizado'], true)) {
                        throw ValidationException::withMessages(['motivo' => $newStatus === 'en_espera' ? 'Indica el motivo de la espera.' : 'Explica por qué el trabajo no se realizó.']);
                    }
                    if ($motivo === null && $work->isFinal()) {
                        throw ValidationException::withMessages(['motivo' => 'Para reabrir un trabajo '.mb_strtolower($work->statusLabel()).' explica el motivo.']);
                    }
                }

                $corrected = null;
                if ($type === 'correccion') {
                    $corrected = ActualizacionReparacion::query()
                        ->where('id_orden', $lockedOrder->id_orden)
                        ->where('id_trabajo', $work->id_trabajo)
                        ->whereKey((int) ($data['id_actualizacion_corregida'] ?? 0))
                        ->first();
                    if ($corrected === null || ! $corrected->isPublished()) {
                        throw ValidationException::withMessages(['id_actualizacion_corregida' => 'Selecciona la publicación de este trabajo que vas a corregir.']);
                    }
                }

                if ($newStatus !== null) {
                    $now = now();
                    $work->estado = $newStatus;
                    if ($newStatus === 'en_proceso' && $work->fecha_inicio === null) {
                        $work->fecha_inicio = $now;
                    }
                    $work->fecha_finalizacion = in_array($newStatus, TrabajoReparacion::FINAL_STATUSES, true) ? $now : null;
                }
                $work->id_usuario_responsable = $user->getKey();
                // touch() guarda los cambios y actualiza fecha_actualizacion aunque solo se registre un avance.
                $work->touch();

                $publish = $data['visibilidad'] === 'cliente';
                $update = ActualizacionReparacion::query()->create([
                    'id_orden' => $lockedOrder->id_orden,
                    'id_trabajo' => $work->id_trabajo,
                    'id_usuario' => $user->getKey(),
                    'tipo' => $type,
                    'id_actualizacion_corregida' => $corrected?->id_actualizacion,
                    'titulo' => trim($data['titulo']),
                    'descripcion' => trim((string) ($data['descripcion'] ?? '')) ?: null,
                    'motivo' => $motivo,
                    'estado_anterior' => $newStatus !== null ? $previousStatus : null,
                    'estado_nuevo' => $newStatus,
                    'visible_cliente' => $publish,
                    'fecha_publicacion' => $publish ? now() : null,
                    'id_usuario_publicacion' => $publish ? $user->getKey() : null,
                ]);

                $this->storePhotos($lockedOrder, $update, $photos, $user, $publish, $storedPaths);

                return $update;
            });
        } catch (Throwable $exception) {
            $this->removeStoredFiles($storedPaths);

            throw $exception;
        }
    }

    /**
     * Revisión final de la orden: registro de nivel de orden (sin trabajo) que habilita marcarla como lista.
     *
     * @param  array{titulo?: ?string, descripcion: string, visibilidad: string}  $data
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws ValidationException
     */
    public function recordFinalReview(OrdenServicio $orden, User $user, array $data, array $photos): ActualizacionReparacion
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($orden, $user, $data, $photos, &$storedPaths): ActualizacionReparacion {
                $lockedOrder = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id_orden);
                if (! $lockedOrder->isInRepair()) {
                    throw ValidationException::withMessages(['revision' => 'La revisión final se registra mientras la orden está en reparación.']);
                }
                if ($this->works->activeWorks($lockedOrder)->isEmpty()) {
                    throw ValidationException::withMessages(['revision' => 'La orden no tiene trabajos autorizados en seguimiento.']);
                }

                $publish = $data['visibilidad'] === 'cliente';
                $update = ActualizacionReparacion::query()->create([
                    'id_orden' => $lockedOrder->id_orden,
                    'id_trabajo' => null,
                    'id_usuario' => $user->getKey(),
                    'tipo' => 'revision_final',
                    'titulo' => trim((string) ($data['titulo'] ?? '')) ?: 'Revisión final del vehículo',
                    'descripcion' => trim($data['descripcion']),
                    'visible_cliente' => $publish,
                    'fecha_publicacion' => $publish ? now() : null,
                    'id_usuario_publicacion' => $publish ? $user->getKey() : null,
                ]);

                $this->storePhotos($lockedOrder, $update, $photos, $user, $publish, $storedPaths);

                return $update;
            });
        } catch (Throwable $exception) {
            $this->removeStoredFiles($storedPaths);

            throw $exception;
        }
    }

    /**
     * Publica al cliente un registro interno junto con sus fotografías. No es reversible: una publicación solo se
     * corrige agregando un registro de corrección.
     *
     * @throws ValidationException
     */
    public function publish(OrdenServicio $orden, ActualizacionReparacion $update, User $user): void
    {
        DB::transaction(function () use ($orden, $update, $user): void {
            $locked = ActualizacionReparacion::query()
                ->where('id_orden', $orden->id_orden)
                ->whereKey($update->id_actualizacion)
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                abort(404);
            }
            if ($locked->isPublished()) {
                throw ValidationException::withMessages(['publicar' => 'Esta actualización ya está publicada.']);
            }
            if ($locked->id_trabajo !== null && ! TrabajoReparacion::query()
                ->whereKey($locked->id_trabajo)
                ->where('id_orden', $orden->id_orden)
                ->whereHas('cotizacion', fn ($query) => $query->whereIn('estado', Cotizacion::AUTHORIZED_STATUSES))
                ->exists()) {
                throw ValidationException::withMessages(['publicar' => 'El trabajo de esta actualización ya no forma parte de una autorización vigente.']);
            }

            $locked->forceFill([
                'visible_cliente' => true,
                'fecha_publicacion' => now(),
                'id_usuario_publicacion' => $user->getKey(),
            ])->save();

            Evidencia::query()
                ->where('id_orden', $orden->id_orden)
                ->where('id_actualizacion', $locked->id_actualizacion)
                ->where('etapa', Evidencia::ETAPA_REPARACION)
                ->update(['visible_cliente' => true]);
        });
    }

    /**
     * Cambia manualmente la etapa general de la orden y lo registra en historial_estados.
     *
     * @throws ValidationException
     */
    public function transitionOrder(OrdenServicio $orden, string $target, User $user, ?string $comment, bool $acknowledgedWarnings): void
    {
        DB::transaction(function () use ($orden, $target, $user, $comment, $acknowledgedWarnings): void {
            $locked = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id_orden);
            $allowedFrom = self::ORDER_TRANSITIONS[$target] ?? [];
            if (! in_array($locked->estado, $allowedFrom, true)) {
                throw ValidationException::withMessages(['estado_orden' => 'La orden no puede pasar de «'.(OrdenServicio::STATUS_LABELS[$locked->estado] ?? $locked->estado).'» a «'.(OrdenServicio::STATUS_LABELS[$target] ?? $target).'».']);
            }

            $comment = trim((string) $comment) ?: null;
            $activeWorks = $this->works->activeWorks($locked);

            $defaultComment = match ($target) {
                'esperando_aprobacion' => $this->assertAwaitingQuotation($locked),
                'en_reparacion' => $this->assertCanStartRepair($locked, $activeWorks->count(), $comment),
                'esperando_refacciones' => $comment ?? throw ValidationException::withMessages(['comentario' => 'Indica qué refacciones se esperan.']),
                'lista_entrega' => $this->assertCanMarkReady($locked, $activeWorks, $acknowledgedWarnings),
            };

            $previous = $locked->estado;
            $locked->estado = $target;
            $locked->save();

            HistorialEstado::query()->create([
                'id_orden' => $locked->id_orden,
                'id_usuario' => $user->getKey(),
                'estado_anterior' => $previous,
                'estado_nuevo' => $target,
                'comentario' => $comment ?? $defaultComment,
            ]);
        });
    }

    /**
     * Revoca un enlace privado del cliente de esta orden (por ejemplo, si se compartió por error).
     *
     * @throws ValidationException
     */
    public function revokeAccess(OrdenServicio $orden, AccesoCliente $access, User $user, string $reason, ?Request $request = null): void
    {
        DB::transaction(function () use ($orden, $access, $user, $reason, $request): void {
            $locked = AccesoCliente::query()
                ->where('id_orden', $orden->id_orden)
                ->whereKey($access->id_acceso)
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->id_cotizacion === null) {
                abort(404);
            }
            if ($locked->revocado) {
                throw ValidationException::withMessages(['acceso' => 'Este enlace ya estaba revocado.']);
            }

            $locked->forceFill(['revocado' => true])->save();
            $cotizacion = Cotizacion::query()->findOrFail($locked->id_cotizacion);

            EventoCotizacion::record($cotizacion, 'revocada', [
                'motivo' => $reason,
                'accesos' => [$locked->id_acceso],
                'origen' => 'seguimiento',
            ], $user->getKey(), null, $request);
        });
    }

    private function assertAcceptsUpdates(OrdenServicio $orden): void
    {
        if (! $orden->isInRepair() && $orden->estado !== 'lista_entrega') {
            throw ValidationException::withMessages(['id_trabajo' => 'Inicia formalmente la reparación de la orden antes de registrar avances.']);
        }
    }

    private function assertAwaitingQuotation(OrdenServicio $orden): string
    {
        $awaiting = Cotizacion::query()->where('id_orden', $orden->id_orden)->where('estado', 'enviada')->where('decision_cliente', 'pendiente')->exists();
        if (! $awaiting) {
            throw ValidationException::withMessages(['estado_orden' => 'No hay una cotización enviada pendiente de respuesta del cliente.']);
        }

        return 'Cotización enviada; en espera de la autorización del cliente.';
    }

    private function assertCanStartRepair(OrdenServicio $orden, int $activeWorks, ?string $comment): string
    {
        if ($activeWorks === 0) {
            throw ValidationException::withMessages(['estado_orden' => 'No hay trabajos autorizados por el cliente. La reparación inicia después de una autorización total o parcial.']);
        }
        if ($orden->estado === 'lista_entrega' && $comment === null) {
            throw ValidationException::withMessages(['comentario' => 'Explica por qué se reanuda la reparación de una orden lista para entrega.']);
        }

        return in_array($orden->estado, ['esperando_refaccion', 'esperando_refacciones'], true)
            ? 'Refacciones disponibles; se reanuda la reparación.'
            : 'Inicio formal de la reparación de los trabajos autorizados.';
    }

    /**
     * @param  iterable<TrabajoReparacion>  $activeWorks
     */
    private function assertCanMarkReady(OrdenServicio $orden, iterable $activeWorks, bool $acknowledgedWarnings): string
    {
        $progress = $this->works->progress($activeWorks);
        if ($progress['total'] === 0) {
            throw ValidationException::withMessages(['estado_orden' => 'La orden no tiene trabajos autorizados en seguimiento.']);
        }
        if ($this->works->currentFinalReview($orden) === null) {
            throw ValidationException::withMessages(['estado_orden' => 'Registra la revisión final antes de marcar el vehículo como listo para entrega.']);
        }
        if ($progress['completado'] < $progress['total'] && ! $acknowledgedWarnings) {
            throw ValidationException::withMessages(['confirmar_advertencias' => 'Hay trabajos que no están completados. Confirma que revisaste el resumen para continuar.']);
        }

        return "Vehículo listo para entrega. Trabajos: {$progress['completado']} completado(s), {$progress['no_realizado']} no realizado(s), "
            .($progress['pendiente'] + $progress['en_proceso'] + $progress['en_espera'])." sin concluir, de {$progress['total']}.";
    }

    /**
     * Guarda las fotografías en el disco privado bajo ordenes/{orden}/reparacion/{actualizacion}/{uuid}.{ext}.
     *
     * @param  array<int, UploadedFile>  $photos
     * @param  array<int, string>  $storedPaths
     */
    private function storePhotos(OrdenServicio $orden, ActualizacionReparacion $update, array $photos, User $user, bool $visible, array &$storedPaths): void
    {
        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['fotografias' => 'Puedes adjuntar hasta '.self::MAX_PHOTOS.' fotografías por actualización.']);
        }

        $disk = Storage::disk('private');
        $directory = 'ordenes/'.$orden->id_orden.'/reparacion/'.$update->id_actualizacion;

        foreach ($photos as $photo) {
            $mimeType = $photo->getMimeType();
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw ValidationException::withMessages(['fotografias' => 'Solo se admiten imágenes JPEG, PNG o WebP.']),
            };
            $internalName = Str::uuid().'.'.$extension;
            $expectedPath = $directory.'/'.$internalName;
            $storedPaths[] = $expectedPath;
            $path = $disk->putFileAs($directory, $photo, $internalName);

            if (! is_string($path) || $path !== $expectedPath) {
                throw new RuntimeException('No se pudo guardar una fotografía de la reparación.');
            }

            $evidence = new Evidencia;
            $evidence->id_orden = $orden->id_orden;
            $evidence->id_diagnostico = null;
            $evidence->id_actualizacion = $update->id_actualizacion;
            $evidence->id_usuario = $user->getKey();
            $evidence->tipo_archivo = 'imagen';
            $evidence->etapa = Evidencia::ETAPA_REPARACION;
            $evidence->nombre_archivo = $this->safeOriginalFilename($photo);
            $evidence->ruta_archivo = $path;
            $evidence->tipo_mime = $mimeType;
            $evidence->descripcion = null;
            $evidence->visible_cliente = $visible;
            $evidence->save();
        }
    }

    private function safeOriginalFilename(UploadedFile $photo): string
    {
        $name = basename(str_replace('\\', '/', $photo->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 255));

        return $name !== '' ? $name : 'fotografia';
    }

    /** @param  array<int, string>  $storedPaths */
    private function removeStoredFiles(array $storedPaths): void
    {
        if ($storedPaths !== []) {
            Storage::disk('private')->delete($storedPaths);
        }
    }
}
