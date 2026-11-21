<?php

namespace App\Services\Cotizaciones;

use App\Mail\CotizacionEnviada;
use App\Models\AccesoCliente;
use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
use App\Models\EventoCotizacion;
use App\Models\OrdenServicio;
use App\Models\User;
use App\Services\Mail\MailDeliveryGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * Envío de cotizaciones al cliente, reenvío, nuevas versiones, revocación de accesos y vencimiento.
 * Toda modificación de datos ocurre en transacciones; el correo se manda después de confirmar la transacción.
 */
class QuotationDispatchService
{
    /** Estados de la orden en los que ya no se envían cotizaciones al cliente. */
    private const CLOSED_ORDER_STATUSES = ['cancelada', 'cerrada', 'entregada'];

    /** Estados que pasan a "sustituida" cuando se envía una versión posterior. */
    private const SUPERSEDABLE_STATUSES = ['enviada', 'aprobada', 'parcialmente_aprobada', 'rechazada', 'vencida'];

    public function __construct(private MailDeliveryGuard $mailGuard) {}

    /**
     * @return array{status: 'sent'|'simulated'|'failed'|'blocked', message: string}
     */
    public function send(Cotizacion $cotizacion, User $user, ?Request $request = null): array
    {
        $this->assertMailCanBeDelivered();

        [$locked, $access, $token] = DB::transaction(function () use ($cotizacion, $user, $request): array {
            $locked = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id_cotizacion);
            if (! $locked->isEditableDraft()) {
                throw ValidationException::withMessages(['envio' => 'Solo se puede enviar una cotización en borrador.']);
            }

            [$orden, $recipient] = $this->resolveRecipient($locked);
            $this->mailGuard->assertRecipientAllowed($recipient);
            $this->assertDraftIsSendable($locked);

            $this->supersedePreviousVersions($locked, $user, $request);
            $this->revokeAccesses($locked, 'Nuevo envío de la cotización.', $user->getKey(), $request);
            [$access, $token] = $this->createAccess($locked, $orden, $user, $recipient);

            $locked->recalculateTotals();
            $locked->forceFill([
                'estado' => 'enviada',
                'decision_cliente' => 'pendiente',
                'fecha_envio' => now(),
                'fecha_vencimiento' => $access->fecha_expiracion,
                'fecha_decision' => null,
                'importe_autorizado' => null,
            ]);
            // Invalida formularios de edición abiertos en otras pestañas.
            $locked->revision++;
            $locked->huella_contenido = $locked->contentFingerprint();
            $locked->save();

            EventoCotizacion::record($locked, 'enviada', [
                'correo_destino' => $recipient,
                'version' => $locked->version,
                'total' => (string) $locked->total,
                'vence' => $access->fecha_expiracion->toIso8601String(),
                'modo_correo' => $this->mailGuard->mode(),
            ], $user->getKey(), $access->id_acceso, $request);

            return [$locked, $access, $token];
        });

        return $this->deliver($locked, $access, $token, $user, $request);
    }

    /**
     * Genera un enlace nuevo (el anterior se revoca) y reinicia la vigencia de una cotización pendiente de respuesta.
     *
     * @return array{status: 'sent'|'simulated'|'failed'|'blocked', message: string}
     */
    public function resend(Cotizacion $cotizacion, User $user, ?Request $request = null): array
    {
        $this->assertMailCanBeDelivered();

        if ($this->expireIfOverdue($cotizacion, $request, 'reenvio')) {
            throw ValidationException::withMessages(['envio' => 'La cotización venció antes de reenviarse. Crea una nueva versión para enviar una propuesta vigente.']);
        }

        $outcome = DB::transaction(function () use ($cotizacion, $user, $request): array {
            $locked = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id_cotizacion);
            if ($locked->hasClientDecision()) {
                throw ValidationException::withMessages(['envio' => 'El cliente ya registró su decisión. Para proponer cambios crea una nueva versión.']);
            }
            if (! $locked->isAwaitingDecision()) {
                throw ValidationException::withMessages(['envio' => 'Solo se puede reenviar una cotización enviada que siga pendiente de respuesta.']);
            }
            if (! $locked->hasValidFingerprint()) {
                return ['integrity' => false, 'cotizacion' => $locked];
            }

            [$orden, $recipient] = $this->resolveRecipient($locked);
            $this->mailGuard->assertRecipientAllowed($recipient);
            $this->revokeAccesses($locked, 'Reenvío de la cotización.', $user->getKey(), $request);
            [$access, $token] = $this->createAccess($locked, $orden, $user, $recipient);

            $locked->forceFill(['fecha_vencimiento' => $access->fecha_expiracion])->save();

            EventoCotizacion::record($locked, 'reenviada', [
                'correo_destino' => $recipient,
                'version' => $locked->version,
                'vence' => $access->fecha_expiracion->toIso8601String(),
                'modo_correo' => $this->mailGuard->mode(),
            ], $user->getKey(), $access->id_acceso, $request);

            return ['integrity' => true, 'cotizacion' => $locked, 'access' => $access, 'token' => $token];
        });

        if (! $outcome['integrity']) {
            $this->recordIntegrityFailure($outcome['cotizacion'], null, 'reenvio', $user->getKey(), $request);

            throw ValidationException::withMessages(['envio' => 'El contenido de la cotización no coincide con lo que se envió al cliente. No se reenvió; revisa la cotización y crea una nueva versión.']);
        }

        return $this->deliver($outcome['cotizacion'], $outcome['access'], $outcome['token'], $user, $request);
    }

    /**
     * Clona la cotización en un borrador con la versión siguiente. Si ya existe el borrador derivado se reutiliza.
     *
     * @return array{cotizacion: Cotizacion, created: bool}
     */
    public function createVersion(Cotizacion $cotizacion, User $user, ?Request $request = null): array
    {
        return DB::transaction(function () use ($cotizacion, $user, $request): array {
            $locked = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id_cotizacion);
            if ($locked->isEditableDraft()) {
                throw ValidationException::withMessages(['version' => 'Un borrador se modifica directamente; no necesita una nueva versión.']);
            }
            if ($locked->estado === 'sustituida') {
                throw ValidationException::withMessages(['version' => 'Esta versión ya fue sustituida por una posterior.']);
            }

            $existing = Cotizacion::query()->where('id_cotizacion_origen', $locked->id_cotizacion)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->isEditableDraft()) {
                    return ['cotizacion' => $existing, 'created' => false];
                }

                throw ValidationException::withMessages(['version' => 'Ya existe la versión '.$existing->version.' ('.$existing->folio.') derivada de esta cotización.']);
            }

            $nextVersion = $locked->version + 1;
            $copy = $locked->replicate([
                'folio', 'estado', 'decision_cliente', 'fecha_envio', 'fecha_decision', 'fecha_vencimiento',
                'importe_autorizado', 'huella_contenido', 'revision', 'version', 'id_cotizacion_origen', 'id_usuario',
            ]);
            $copy->forceFill([
                'folio' => $this->versionFolio($locked->folio, $nextVersion),
                'id_usuario' => $user->getKey(),
                'estado' => 'borrador',
                'decision_cliente' => 'pendiente',
                'revision' => 1,
                'version' => $nextVersion,
                'id_cotizacion_origen' => $locked->id_cotizacion,
            ])->save();

            foreach ($locked->detalles()->orderBy('id_detalle')->get() as $detail) {
                $detailCopy = $detail->replicate(['id_cotizacion', 'decision_cliente', 'riesgo_confirmado_en']);
                $detailCopy->forceFill(['id_cotizacion' => $copy->id_cotizacion, 'decision_cliente' => 'pendiente'])->save();
            }

            $copy->recalculateTotals();
            $copy->save();

            EventoCotizacion::record($locked, 'version_creada', [
                'nueva_version' => $nextVersion,
                'folio' => $copy->folio,
            ], $user->getKey(), null, $request);

            return ['cotizacion' => $copy, 'created' => true];
        });
    }

    /**
     * Marca como vencida una cotización pendiente cuya vigencia terminó y revoca sus accesos. Idempotente.
     */
    public function expireIfOverdue(Cotizacion $cotizacion, ?Request $request = null, string $origin = 'portal'): bool
    {
        return DB::transaction(function () use ($cotizacion, $request, $origin): bool {
            $locked = Cotizacion::query()->lockForUpdate()->find($cotizacion->id_cotizacion);
            if ($locked === null || ! $locked->isAwaitingDecision() || ! $locked->isPastDueDate()) {
                return false;
            }

            $locked->forceFill(['estado' => 'vencida'])->save();
            $revoked = $this->revokeAccesses($locked, 'Vencimiento de la cotización.', null, null, false);

            EventoCotizacion::record($locked, 'vencida', [
                'origen' => $origin,
                'vencio' => $locked->fecha_vencimiento?->toIso8601String(),
                'accesos_revocados' => $revoked,
            ], null, null, $request);

            return true;
        });
    }

    /**
     * Registra una sola vez por acceso (o por cotización en operaciones internas) que la huella no coincide.
     */
    public function recordIntegrityFailure(Cotizacion $cotizacion, ?int $accessId, string $context, ?int $userId = null, ?Request $request = null): void
    {
        $alreadyRecorded = EventoCotizacion::query()
            ->where('id_cotizacion', $cotizacion->id_cotizacion)
            ->where('tipo', 'integridad_invalida')
            ->when($accessId !== null, fn ($query) => $query->where('id_acceso', $accessId), fn ($query) => $query->whereNull('id_acceso'))
            ->exists();

        if (! $alreadyRecorded) {
            EventoCotizacion::record($cotizacion, 'integridad_invalida', ['contexto' => $context], $userId, $accessId, $request);
        }
    }

    public function accessUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/').route('portal.cotizaciones.show', ['token' => $token], false);
    }

    /**
     * @return array{status: 'sent'|'simulated'|'failed'|'blocked', message: string}
     */
    private function deliver(Cotizacion $cotizacion, AccesoCliente $access, string $token, User $user, ?Request $request): array
    {
        $cotizacion->load(['orden.vehiculo.cliente']);
        $mode = $this->mailGuard->mode();
        $simulated = $mode === MailDeliveryGuard::MODE_SIMULATED;
        $modeLabel = $mode === MailDeliveryGuard::MODE_LOCAL_TEST ? 'Envío SMTP real (modo de prueba local)' : 'Envío SMTP real';

        try {
            $sent = Mail::to($access->correo_destino)->send(new CotizacionEnviada($cotizacion, $this->accessUrl($token), $access->fecha_expiracion));
        } catch (Throwable $exception) {
            $failure = $this->classifyFailure($exception);
            EventoCotizacion::record($cotizacion, 'envio_fallido', [
                'correo_destino' => $access->correo_destino,
                'modo_correo' => $mode,
                'categoria' => $failure['categoria'],
                'codigo_smtp' => $failure['codigo'],
                'error' => class_basename($exception),
                'detalle' => $failure['detalle'],
            ], $user->getKey(), $access->id_acceso, $request);
            Log::warning('No se pudo entregar el correo de una cotización al servidor de correo.', [
                'id_cotizacion' => $cotizacion->id_cotizacion,
                'categoria' => $failure['categoria'],
                'codigo_smtp' => $failure['codigo'],
                'error' => $exception::class,
            ]);

            return [
                'status' => 'failed',
                'message' => $modeLabel.' rechazado: '.self::FAILURE_CATEGORIES[$failure['categoria']].' La cotización quedó registrada como enviada, pero el cliente no recibirá el enlace; corrige la causa y usa «Reenviar» para generar un enlace nuevo.',
            ];
        }

        // Un listener de MessageSending canceló el correo (barrera de desarrollo local): no salió nada hacia el servidor.
        if ($sent === null) {
            EventoCotizacion::record($cotizacion, 'envio_fallido', [
                'correo_destino' => $access->correo_destino,
                'modo_correo' => $mode,
                'categoria' => 'bloqueado_local',
            ], $user->getKey(), $access->id_acceso, $request);

            return [
                'status' => 'blocked',
                'message' => 'El correo no se envió: '.self::FAILURE_CATEGORIES['bloqueado_local'].' La cotización quedó registrada como enviada; usa «Reenviar» cuando la configuración sea correcta.',
            ];
        }

        if ($simulated) {
            return [
                'status' => 'simulated',
                'message' => 'Modo simulación: el correo para '.$access->correo_destino.' se escribió en el registro del servidor (storage/logs) y NO se entregó al cliente.',
            ];
        }

        // El servidor SMTP respondió 250 al final de DATA: aceptó el mensaje para entregarlo. Eso no confirma la bandeja de entrada.
        $messageId = (string) $sent->getSymfonySentMessage()->getMessageId();
        EventoCotizacion::record($cotizacion, 'envio_aceptado', [
            'correo_destino' => $access->correo_destino,
            'modo_correo' => $mode,
            'id_mensaje' => mb_substr($messageId, 0, 120),
        ], $user->getKey(), $access->id_acceso, $request);

        return [
            'status' => 'sent',
            'message' => $modeLabel.': el servidor SMTP ('.config('mail.mailers.smtp.host').') aceptó el mensaje para '.$access->correo_destino.($messageId !== '' ? ' (id '.$messageId.')' : '').'. Esto confirma que lo recibió, no que haya llegado a la bandeja de entrada: revisa también Spam y el registro de envíos de Brevo.',
        ];
    }

    /** Causas de un envío fallido, ya redactadas para el mecánico. */
    public const FAILURE_CATEGORIES = [
        'autenticacion' => 'el servidor SMTP rechazó el inicio de sesión. Revisa MAIL_USERNAME (SMTP Login de Brevo) y MAIL_PASSWORD (SMTP Key, no una API key).',
        'remitente_rechazado' => 'el servidor SMTP rechazó el remitente. MAIL_FROM_ADDRESS debe estar dado de alta y verificado en Brevo (Senders).',
        'destinatario_rechazado' => 'el servidor SMTP rechazó la dirección de destino.',
        'mensaje_rechazado' => 'el servidor SMTP rechazó el contenido del mensaje después de recibirlo.',
        'conexion' => 'no se pudo conectar con el servidor SMTP (host, puerto, TLS o red).',
        'rechazo_smtp' => 'el servidor SMTP respondió con un error.',
        'bloqueado_local' => 'en desarrollo local solo se permite entregar a SEIBI_TEST_RECIPIENT con el remitente de MAIL_FROM_ADDRESS.',
        'error_interno' => 'ocurrió un error en la aplicación al preparar el correo.',
    ];

    /**
     * Clasifica el fallo de entrega sin conservar credenciales: el texto de detalle es solo la respuesta del servidor SMTP.
     *
     * @return array{categoria: string, codigo: int|null, detalle: string|null}
     */
    private function classifyFailure(Throwable $exception): array
    {
        if (! $exception instanceof TransportExceptionInterface) {
            return ['categoria' => 'error_interno', 'codigo' => null, 'detalle' => null];
        }

        $message = $exception->getMessage();
        $code = (int) $exception->getCode() ?: null;

        // Respuesta textual del servidor (p. ej. «550 5.7.1 Sender not allowed»), sin el usuario que Symfony antepone.
        $serverReply = preg_match_all('/got code "(\d{3})", with message "([^"]*)"/', $message, $matches) ? end($matches[2]) : null;
        $detail = $serverReply !== null ? $this->redactCredentials($serverReply) : null;

        // Último comando SMTP enviado (sin argumentos sensibles), ignorando RSET/NOOP de recuperación.
        $stage = null;
        if (preg_match_all('/\] > (MAIL FROM|RCPT TO|DATA|AUTH|STARTTLS|EHLO|HELO|\.)/', $exception->getDebug(), $commands)) {
            $stage = end($commands[1]);
        }

        $categoria = match (true) {
            str_starts_with($message, 'Failed to authenticate') || str_starts_with($message, 'Failed to find an authenticator') || $stage === 'AUTH' => 'autenticacion',
            $stage === 'MAIL FROM' || ($serverReply !== null && preg_match('/sender|from address|not.+verified/i', $serverReply) === 1) => 'remitente_rechazado',
            $stage === 'RCPT TO' => 'destinatario_rechazado',
            $stage === 'DATA' || $stage === '.' => 'mensaje_rechazado',
            ! $exception instanceof UnexpectedResponseException || in_array($stage, ['EHLO', 'HELO', 'STARTTLS'], true) => 'conexion',
            default => 'rechazo_smtp',
        };

        return ['categoria' => $categoria, 'codigo' => $code, 'detalle' => $detail];
    }

    private function assertMailCanBeDelivered(): void
    {
        $status = $this->mailGuard->status();
        if ($status['mode'] === MailDeliveryGuard::MODE_BLOCKED) {
            throw ValidationException::withMessages([
                'envio' => array_merge(['El envío de correo está bloqueado por la configuración actual:'], $status['problems']),
            ]);
        }
    }

    /**
     * Comprueba que la orden siga abierta y que el vehículo y el cliente correspondan a la orden de la cotización.
     *
     * @return array{0: OrdenServicio, 1: string}
     */
    private function resolveRecipient(Cotizacion $cotizacion): array
    {
        $orden = OrdenServicio::query()->with('vehiculo.cliente')->find($cotizacion->id_orden);
        $vehiculo = $orden?->vehiculo;
        $cliente = $vehiculo?->cliente;

        if ($orden === null || $vehiculo === null || $cliente === null
            || (int) $vehiculo->id_vehiculo !== (int) $orden->id_vehiculo
            || (int) $cliente->id_cliente !== (int) $vehiculo->id_cliente) {
            throw ValidationException::withMessages(['envio' => 'La orden no tiene un vehículo y un cliente asociados correctamente.']);
        }
        if (in_array($orden->estado, self::CLOSED_ORDER_STATUSES, true)) {
            throw ValidationException::withMessages(['envio' => 'La orden está '.$orden->estado.'; ya no se pueden enviar cotizaciones al cliente.']);
        }

        $email = trim((string) $cliente->correo);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['envio' => 'El cliente no tiene un correo electrónico válido. Recepción debe registrarlo en la ficha del cliente.']);
        }

        return [$orden, $email];
    }

    private function assertDraftIsSendable(Cotizacion $cotizacion): void
    {
        $details = $cotizacion->detalles()->orderBy('id_detalle')->get();
        $errors = [];

        if ($details->isEmpty()) {
            $errors[] = 'Agrega al menos un concepto antes de enviar la cotización.';
        }

        $totalCents = 0;
        foreach ($details as $detail) {
            $amount = DetalleCotizacion::decimalToHundredths((string) $detail->importe);
            $totalCents += $amount;

            if ($detail->prioridad === null || ! array_key_exists($detail->prioridad, DetalleCotizacion::PRIORITIES)) {
                $errors[] = 'Clasifica la prioridad de «'.$detail->descripcion.'» (necesario, recomendado u opcional).';
            }
            if ($amount !== DetalleCotizacion::lineAmountCents((string) $detail->cantidad, (string) $detail->precio_unitario)) {
                $errors[] = 'El importe de «'.$detail->descripcion.'» no coincide con su cantidad y precio. Guarda el borrador de nuevo.';
            }
        }

        if ($details->isNotEmpty() && $totalCents <= 0) {
            $errors[] = 'El total de la cotización debe ser mayor que cero.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['envio' => $errors]);
        }
    }

    /**
     * Marca como sustituidas las versiones anteriores de la cadena y revoca sus accesos. Las decisiones del
     * cliente en esas versiones (decision_cliente, importes y conceptos) se conservan como historial.
     */
    private function supersedePreviousVersions(Cotizacion $cotizacion, User $user, ?Request $request): void
    {
        $ancestorId = $cotizacion->id_cotizacion_origen;
        $visited = [];

        while ($ancestorId !== null && ! in_array($ancestorId, $visited, true)) {
            $visited[] = $ancestorId;
            $ancestor = Cotizacion::query()->lockForUpdate()->find($ancestorId);
            if ($ancestor === null) {
                break;
            }

            if (in_array($ancestor->estado, self::SUPERSEDABLE_STATUSES, true)) {
                $previousStatus = $ancestor->estado;
                $ancestor->forceFill(['estado' => 'sustituida'])->save();
                $this->revokeAccesses($ancestor, 'Sustituida por la versión '.$cotizacion->version.'.', $user->getKey(), $request, false);

                EventoCotizacion::record($ancestor, 'sustituida', [
                    'estado_anterior' => $previousStatus,
                    'sustituida_por' => $cotizacion->folio,
                    'version_nueva' => $cotizacion->version,
                ], $user->getKey(), null, $request);
            }

            $ancestorId = $ancestor->id_cotizacion_origen;
        }
    }

    /**
     * Revoca los accesos vigentes de la cotización. Devuelve cuántos accesos se revocaron.
     */
    private function revokeAccesses(Cotizacion $cotizacion, string $reason, ?int $userId, ?Request $request, bool $recordEvent = true): int
    {
        $accessIds = AccesoCliente::query()
            ->where('id_cotizacion', $cotizacion->id_cotizacion)
            ->where('revocado', false)
            ->lockForUpdate()
            ->pluck('id_acceso')
            ->all();

        if ($accessIds === []) {
            return 0;
        }

        AccesoCliente::query()->whereIn('id_acceso', $accessIds)->update(['revocado' => true]);

        if ($recordEvent) {
            EventoCotizacion::record($cotizacion, 'revocada', ['motivo' => $reason, 'accesos' => $accessIds], $userId, null, $request);
        }

        return count($accessIds);
    }

    /**
     * Crea el acceso privado. El token solo se devuelve para construir el enlace del correo; se guarda su hash.
     *
     * @return array{0: AccesoCliente, 1: string}
     */
    private function createAccess(Cotizacion $cotizacion, OrdenServicio $orden, User $user, string $recipient): array
    {
        $token = AccesoCliente::generateToken();
        $expiresAt = $this->expirationDate();

        $access = AccesoCliente::query()->create([
            'id_orden' => $orden->id_orden,
            'id_cotizacion' => $cotizacion->id_cotizacion,
            'id_usuario' => $user->getKey(),
            'correo_destino' => $recipient,
            'token_hash' => AccesoCliente::hashToken($token),
            'fecha_expiracion' => $expiresAt,
            'revocado' => false,
        ]);

        return [$access, $token];
    }

    private function expirationDate(): Carbon
    {
        return now()->addDays(max(1, (int) config('seibi.quotation_link_ttl_days', 7)));
    }

    /**
     * Folio de una versión posterior: folio de la versión original seguido de -V{n} (por ejemplo COT-20261116-AB12CD34-V2).
     */
    private function versionFolio(string $folio, int $version): string
    {
        $base = preg_replace('/-V\d+$/', '', $folio) ?? $folio;
        $candidate = $base.'-V'.$version;

        while (Cotizacion::query()->where('folio', $candidate)->exists()) {
            $candidate = mb_substr($base, 0, 21).'-V'.$version.'-'.Str::upper(Str::random(4));
        }

        return $candidate;
    }

    private function redactCredentials(string $message): string
    {
        $mailer = (string) config('mail.default');
        foreach (['username', 'password'] as $key) {
            $secret = (string) config("mail.mailers.$mailer.$key");
            if ($secret !== '') {
                $message = str_replace([$secret, base64_encode($secret)], '***', $message);
            }
        }

        return mb_substr($message, 0, 255);
    }
}
