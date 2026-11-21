<?php

namespace App\Services\Reparaciones;

use App\Models\AccesoCliente;
use App\Models\ActualizacionReparacion;
use App\Models\Cotizacion;
use App\Models\HistorialEstado;
use App\Models\OrdenServicio;
use App\Models\TrabajoReparacion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Genera los trabajos de reparación a partir de los conceptos aceptados y arma la información de seguimiento
 * para el panel interno y para el portal del cliente. Solo usa datos guardados; nunca estima avances.
 */
class RepairWorkService
{
    /**
     * Crea un trabajo por cada concepto aceptado de la versión autorizada. Idempotente: id_detalle es único y los
     * conceptos que ya tienen trabajo se omiten, así que reprocesar la autorización no duplica nada.
     * Devuelve cuántos trabajos se crearon.
     */
    public function generateForQuotation(Cotizacion $cotizacion): int
    {
        return DB::transaction(function () use ($cotizacion): int {
            $locked = Cotizacion::query()->lockForUpdate()->find($cotizacion->id_cotizacion);
            if ($locked === null || ! $locked->isAuthorized() || $locked->fecha_decision === null) {
                return 0;
            }

            $accepted = $locked->detalles()
                ->where('decision_cliente', 'aceptado')
                ->orderBy('id_detalle')
                ->get();
            if ($accepted->isEmpty()) {
                return 0;
            }

            $existing = TrabajoReparacion::query()
                ->whereIn('id_detalle', $accepted->modelKeys())
                ->pluck('id_detalle')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $created = 0;
            foreach ($accepted as $detail) {
                if (in_array((int) $detail->id_detalle, $existing, true)) {
                    continue;
                }

                TrabajoReparacion::query()->create([
                    'id_orden' => $locked->id_orden,
                    'id_cotizacion' => $locked->id_cotizacion,
                    'id_detalle' => $detail->id_detalle,
                    'version_cotizacion' => $locked->version,
                    'tipo_concepto' => $detail->tipo_concepto,
                    'descripcion' => $detail->descripcion,
                    'cantidad' => $detail->cantidad,
                    'prioridad' => $detail->prioridad,
                    'estado' => 'pendiente',
                    'fecha_autorizacion' => $locked->fecha_decision,
                ]);
                $created++;
            }

            return $created;
        });
    }

    /**
     * Genera los trabajos faltantes de todas las cotizaciones autorizadas de la orden (por ejemplo, autorizaciones
     * registradas antes de que existiera este módulo). No toca versiones sustituidas ni rechazadas.
     */
    public function syncOrder(OrdenServicio $orden): int
    {
        $pending = Cotizacion::query()
            ->where('id_orden', $orden->id_orden)
            ->whereIn('estado', Cotizacion::AUTHORIZED_STATUSES)
            ->whereHas('detalles', fn ($query) => $query->where('decision_cliente', 'aceptado')->whereDoesntHave('trabajoReparacion'))
            ->get();

        return $pending->sum(fn (Cotizacion $cotizacion): int => $this->generateForQuotation($cotizacion));
    }

    /**
     * Trabajos en seguimiento de la orden: solo los de cotizaciones con autorización vigente.
     * Los de una versión sustituida quedan como historial y no cuentan en el avance.
     *
     * @return Collection<int, TrabajoReparacion>
     */
    public function activeWorks(OrdenServicio $orden): Collection
    {
        return TrabajoReparacion::query()
            ->where('id_orden', $orden->id_orden)
            ->whereHas('cotizacion', fn ($query) => $query->whereIn('estado', Cotizacion::AUTHORIZED_STATUSES))
            ->with(['cotizacion'])
            ->orderBy('id_cotizacion')
            ->orderBy('id_trabajo')
            ->get();
    }

    /**
     * Trabajos de versiones que el cliente autorizó pero que después fueron sustituidas (solo consulta).
     *
     * @return Collection<int, TrabajoReparacion>
     */
    public function supersededWorks(OrdenServicio $orden): Collection
    {
        return TrabajoReparacion::query()
            ->where('id_orden', $orden->id_orden)
            ->whereHas('cotizacion', fn ($query) => $query->whereNotIn('estado', Cotizacion::AUTHORIZED_STATUSES))
            ->with(['cotizacion'])
            ->orderBy('id_trabajo')
            ->get();
    }

    /**
     * Conteo por estado y porcentaje de avance sobre los trabajos autorizados. Los no realizados forman parte del
     * total pero nunca cuentan como completados.
     *
     * @param  iterable<TrabajoReparacion>  $works
     * @return array{total: int, pendiente: int, en_proceso: int, en_espera: int, completado: int, no_realizado: int, percent: int}
     */
    public function progress(iterable $works): array
    {
        $summary = ['total' => 0, 'pendiente' => 0, 'en_proceso' => 0, 'en_espera' => 0, 'completado' => 0, 'no_realizado' => 0];
        foreach ($works as $work) {
            $summary['total']++;
            if (array_key_exists($work->estado, $summary)) {
                $summary[$work->estado]++;
            }
        }
        $summary['percent'] = $summary['total'] === 0 ? 0 : intdiv($summary['completado'] * 100, $summary['total']);

        return $summary;
    }

    /**
     * Revisión final registrada después del último inicio de reparación (una reapertura la invalida).
     */
    public function currentFinalReview(OrdenServicio $orden): ?ActualizacionReparacion
    {
        $lastRepairStart = $this->lastStatusChangeTo($orden, 'en_reparacion');

        return ActualizacionReparacion::query()
            ->where('id_orden', $orden->id_orden)
            ->where('tipo', 'revision_final')
            ->when($lastRepairStart !== null, fn ($query) => $query->where('fecha_creacion', '>=', $lastRepairStart->fecha_cambio))
            ->latest('fecha_creacion')
            ->latest('id_actualizacion')
            ->first();
    }

    public function lastStatusChangeTo(OrdenServicio $orden, string $status): ?HistorialEstado
    {
        return HistorialEstado::query()
            ->where('id_orden', $orden->id_orden)
            ->where('estado_nuevo', $status)
            ->latest('fecha_cambio')
            ->latest('id_historial')
            ->first();
    }

    /**
     * Un enlace de una cotización autorizada sirve para consultar el seguimiento mientras la orden está activa y, una
     * vez entregada, cerrada o cancelada, durante el plazo configurado.
     */
    public function trackingWindowClosed(OrdenServicio $orden): bool
    {
        $closedAt = $this->closedAt($orden);

        return $closedAt !== null
            && $closedAt->copy()->addDays(max(0, (int) config('seibi.tracking_link_days_after_close', 30)))->isPast();
    }

    public function closedAt(OrdenServicio $orden): ?Carbon
    {
        if (! $orden->isClosed()) {
            return null;
        }

        $change = HistorialEstado::query()
            ->where('id_orden', $orden->id_orden)
            ->whereIn('estado_nuevo', OrdenServicio::CLOSED_STATUSES)
            ->latest('fecha_cambio')
            ->value('fecha_cambio');

        return $orden->fecha_cierre
            ?? ($change !== null ? Carbon::parse($change) : null)
            ?? $orden->fecha_actualizacion;
    }

    /**
     * Datos de la pestaña «Seguimiento de reparación» del panel interno.
     *
     * @return array<string, mixed>
     */
    public function internalTracking(OrdenServicio $orden): array
    {
        $this->syncOrder($orden);

        $updateRelations = [
            'usuario', 'usuarioPublicacion', 'corregida',
            'evidencias' => fn ($query) => $query->where('etapa', 'reparacion')->orderBy('id_evidencia'),
        ];
        $works = $this->activeWorks($orden)->load([
            'responsable',
            'actualizaciones' => fn ($query) => $query->with($updateRelations)->orderByDesc('fecha_creacion')->orderByDesc('id_actualizacion'),
        ]);

        $authorizedQuotationIds = Cotizacion::query()
            ->where('id_orden', $orden->id_orden)
            ->whereIn('estado', Cotizacion::AUTHORIZED_STATUSES)
            ->pluck('id_cotizacion');

        return [
            'works' => $works,
            'supersededWorks' => $this->supersededWorks($orden),
            'progress' => $this->progress($works),
            'finalReview' => $this->currentFinalReview($orden),
            'orderUpdates' => ActualizacionReparacion::query()
                ->where('id_orden', $orden->id_orden)
                ->whereNull('id_trabajo')
                ->with($updateRelations)
                ->orderByDesc('fecha_creacion')
                ->orderByDesc('id_actualizacion')
                ->get(),
            'trackingAccesses' => AccesoCliente::query()
                ->where('id_orden', $orden->id_orden)
                ->whereIn('id_cotizacion', $authorizedQuotationIds)
                ->where('revocado', false)
                ->with('cotizacion')
                ->orderByDesc('id_acceso')
                ->get(),
            'hasAwaitingQuotation' => Cotizacion::query()
                ->where('id_orden', $orden->id_orden)
                ->where('estado', 'enviada')
                ->where('decision_cliente', 'pendiente')
                ->exists(),
            'trackingWindowClosed' => $this->trackingWindowClosed($orden),
        ];
    }

    /**
     * Información de seguimiento visible para el cliente. El alcance es la cotización del token: solo sus trabajos y
     * las actualizaciones publicadas de esos trabajos, más las de nivel de orden (revisión final) publicadas.
     * No incluye notas internas, usuarios, precios ni datos de proveedores.
     *
     * @return array<string, mixed>
     */
    public function portalTracking(AccesoCliente $access, Cotizacion $cotizacion): array
    {
        $this->generateForQuotation($cotizacion);
        $orden = $cotizacion->orden;

        $works = TrabajoReparacion::query()
            ->where('id_orden', $orden->id_orden)
            ->where('id_cotizacion', $cotizacion->id_cotizacion)
            ->orderBy('id_trabajo')
            ->get();
        $workIds = $works->modelKeys();

        $updates = ActualizacionReparacion::query()
            ->where('id_orden', $orden->id_orden)
            ->where('visible_cliente', true)
            ->whereNotNull('fecha_publicacion')
            ->where(function ($query) use ($workIds): void {
                $query->whereIn('id_trabajo', $workIds === [] ? [0] : $workIds)
                    ->orWhere(fn ($orderLevel) => $orderLevel->whereNull('id_trabajo')->where('tipo', 'revision_final'));
            })
            ->with(['evidencias' => fn ($query) => $query->where('visible_cliente', true)->orderBy('id_evidencia'), 'trabajo'])
            ->orderByDesc('fecha_publicacion')
            ->orderByDesc('id_actualizacion')
            ->get();

        $latestByWork = $updates->whereNotNull('id_trabajo')->groupBy('id_trabajo')->map(fn ($group) => $group->first());

        $readyChange = in_array($orden->estado, ['lista_entrega', 'entregada', 'cerrada'], true)
            ? $this->lastStatusChangeTo($orden, 'lista_entrega')
            : null;

        $lastUpdate = collect([
            $updates->first()?->fecha_publicacion,
            $cotizacion->fecha_decision,
            $this->lastStatusChangeTo($orden, $orden->estado)?->fecha_cambio,
        ])->filter()->max();

        return [
            'works' => $works,
            'progress' => $this->progress($works),
            'updates' => $updates,
            'latestByWork' => $latestByWork,
            'stages' => $this->portalStages($orden, $cotizacion),
            'headline' => $this->portalHeadline($orden, $works),
            'readyAt' => $readyChange?->fecha_cambio,
            'isReady' => $readyChange !== null,
            'lastUpdate' => $lastUpdate,
        ];
    }

    /**
     * Etapas generales derivadas de estados y eventos registrados. Ninguna avanza por el simple envío de la cotización.
     *
     * @return list<array{key: string, label: string, state: 'done'|'current'|'upcoming', date: ?\DateTimeInterface}>
     */
    public function portalStages(OrdenServicio $orden, Cotizacion $cotizacion): array
    {
        $diagnosis = $this->lastStatusChangeTo($orden, 'en_diagnostico');
        $repairStart = HistorialEstado::query()
            ->where('id_orden', $orden->id_orden)
            ->where('estado_nuevo', 'en_reparacion')
            ->oldest('fecha_cambio')
            ->first();
        $finalReview = $this->currentFinalReview($orden);
        $ready = in_array($orden->estado, ['lista_entrega', 'entregada', 'cerrada'], true)
            ? $this->lastStatusChangeTo($orden, 'lista_entrega')
            : null;
        $inRepair = $orden->isInRepair() || $ready !== null;

        $reached = [
            'recibido' => [true, $orden->fecha_ingreso],
            'diagnostico' => [$diagnosis !== null || $orden->estado !== 'recibida', $diagnosis?->fecha_cambio],
            'autorizacion' => [$cotizacion->fecha_decision !== null, $cotizacion->fecha_decision],
            'reparacion' => [$repairStart !== null && $inRepair, $repairStart?->fecha_cambio],
            'revision_final' => [$finalReview !== null, $finalReview?->fecha_creacion],
            'listo' => [$ready !== null, $ready?->fecha_cambio],
        ];
        $labels = [
            'recibido' => 'Vehículo recibido',
            'diagnostico' => 'Diagnóstico',
            'autorizacion' => 'Autorización',
            'reparacion' => 'Reparación',
            'revision_final' => 'Revisión final',
            'listo' => 'Listo para entrega',
        ];

        // La etapa actual es la última alcanzada; las posteriores quedan pendientes aunque haya datos sueltos.
        $keys = array_keys($labels);
        $lastReached = 0;
        foreach ($keys as $index => $key) {
            if ($reached[$key][0]) {
                $lastReached = $index;
            }
        }

        $stages = [];
        foreach ($keys as $index => $key) {
            [$done, $date] = $reached[$key];
            $state = match (true) {
                $index > $lastReached || ! $done => 'upcoming',
                $index === $lastReached && $key !== 'listo' => 'current',
                default => 'done',
            };
            $stages[] = ['key' => $key, 'label' => $labels[$key], 'state' => $state, 'date' => $done ? $date : null];
        }

        return $stages;
    }

    /**
     * @param  Collection<int, TrabajoReparacion>  $works
     */
    private function portalHeadline(OrdenServicio $orden, Collection $works): string
    {
        if (in_array($orden->estado, ['lista_entrega', 'entregada', 'cerrada'], true)) {
            return $orden->estado === 'lista_entrega' ? 'Tu vehículo está listo para entrega.' : 'La reparación de tu vehículo concluyó.';
        }
        if ($orden->estado === 'cancelada') {
            return 'La orden de servicio fue cancelada. Comunícate con el taller si tienes dudas.';
        }
        if ($this->currentFinalReview($orden) !== null) {
            return 'Tu vehículo se encuentra en revisión final.';
        }
        if (in_array($orden->estado, ['esperando_refaccion', 'esperando_refacciones'], true)) {
            return 'Estamos esperando refacciones para continuar con los servicios autorizados.';
        }
        if ($orden->estado === 'en_reparacion') {
            return $works->contains('estado', 'en_proceso')
                ? 'Estamos trabajando en los servicios autorizados.'
                : 'Tu vehículo está en reparación.';
        }

        return 'Recibimos tu autorización. El taller te mostrará aquí los avances cuando inicie la reparación.';
    }
}
