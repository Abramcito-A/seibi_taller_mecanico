<?php

namespace App\Services\Cotizaciones;

use App\Models\AccesoCliente;
use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
use App\Models\EventoCotizacion;
use App\Services\Reparaciones\RepairWorkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Acceso del cliente mediante el enlace privado y registro de su decisión sobre los conceptos.
 * El importe autorizado se calcula solo con los importes guardados en la base de datos, en centésimas enteras.
 */
class QuotationDecisionService
{
    public const ACCESS_VALID = 'valid';

    public const ACCESS_INVALID = 'invalid';

    public const ACCESS_EXPIRED = 'expired';

    public const ACCESS_SUPERSEDED = 'superseded';

    public const ACCESS_REPLACED = 'replaced';

    public const ACCESS_INTEGRITY = 'integrity';

    public const ACCESS_TRACKING_ENDED = 'tracking_ended';

    /** Estados visibles para el cliente: pendiente de respuesta o con una decisión registrada. */
    private const VISIBLE_STATUSES = ['enviada', 'aprobada', 'parcialmente_aprobada', 'rechazada'];

    public function __construct(
        private QuotationDispatchService $dispatch,
        private RepairWorkService $repairWorks,
    ) {}

    /**
     * Resuelve el token sin revelar si existe cuando no es válido. Comprueba formato, hash, revocación,
     * vigencia, relación acceso–cotización–orden y la huella del contenido.
     *
     * @return array{status: string, access?: AccesoCliente, cotizacion?: Cotizacion}
     */
    public function resolveAccess(string $token, Request $request): array
    {
        if (! AccesoCliente::hasValidTokenFormat($token)) {
            return ['status' => self::ACCESS_INVALID];
        }

        $access = AccesoCliente::query()->where('token_hash', AccesoCliente::hashToken($token))->first();
        if ($access === null || $access->id_cotizacion === null) {
            return ['status' => self::ACCESS_INVALID];
        }

        $cotizacion = Cotizacion::query()
            ->with(['orden.vehiculo.cliente', 'detalles' => fn ($query) => $query->orderBy('id_detalle')])
            ->find($access->id_cotizacion);

        if ($cotizacion === null
            || (int) $cotizacion->id_orden !== (int) $access->id_orden
            || $cotizacion->orden?->vehiculo?->cliente === null) {
            return ['status' => self::ACCESS_INVALID];
        }

        if ($access->revocado) {
            return ['status' => match ($cotizacion->estado) {
                'sustituida' => self::ACCESS_SUPERSEDED,
                'vencida' => self::ACCESS_EXPIRED,
                default => self::ACCESS_REPLACED,
            }];
        }

        if ($cotizacion->isAwaitingDecision() && ($access->isExpired() || $cotizacion->isPastDueDate())) {
            $this->dispatch->expireIfOverdue($cotizacion, $request, 'portal');

            return ['status' => self::ACCESS_EXPIRED];
        }

        // Dos periodos: la vigencia de fecha_expiracion limita la decisión (y la vista de resultado de una cotización
        // rechazada). Tras una autorización total o parcial el enlace queda en seguimiento de solo lectura mientras la
        // orden esté activa y, una vez cerrada, durante el plazo configurado; la revocación sigue aplicando.
        if ($cotizacion->isAuthorized()) {
            if ($this->repairWorks->trackingWindowClosed($cotizacion->orden)) {
                return ['status' => self::ACCESS_TRACKING_ENDED];
            }
        } elseif ($access->isExpired() || $cotizacion->estado === 'vencida') {
            return ['status' => self::ACCESS_EXPIRED];
        }

        if (! in_array($cotizacion->estado, self::VISIBLE_STATUSES, true)) {
            return ['status' => self::ACCESS_INVALID];
        }

        if (! $cotizacion->hasValidFingerprint()) {
            $this->dispatch->recordIntegrityFailure($cotizacion, $access->id_acceso, 'consulta', null, $request);

            return ['status' => self::ACCESS_INTEGRITY];
        }

        return ['status' => self::ACCESS_VALID, 'access' => $access, 'cotizacion' => $cotizacion];
    }

    /**
     * Actualiza el último acceso y registra la primera consulta del enlace (sin duplicar eventos en cada visita).
     */
    public function registerView(AccesoCliente $access, Cotizacion $cotizacion, Request $request): void
    {
        $firstView = $access->ultimo_acceso === null;
        $access->forceFill(['ultimo_acceso' => now()])->save();

        if ($firstView) {
            EventoCotizacion::record($cotizacion, 'consultada', ['version' => $cotizacion->version], null, $access->id_acceso, $request);
        }
    }

    /**
     * Registra la decisión del cliente una sola vez por versión.
     *
     * @param  array{decisiones: array<int|string, string>, confirmar_riesgo?: array<int|string, string>|null, version: int|string, revision: int|string}  $input
     * @return array{status: 'recorded'|'already_decided'|'expired'|'integrity'|'unavailable', cotizacion: Cotizacion}
     *
     * @throws ValidationException
     */
    public function decide(AccesoCliente $access, array $input, Request $request): array
    {
        $result = DB::transaction(function () use ($access, $input, $request): array {
            $locked = Cotizacion::query()->lockForUpdate()->findOrFail($access->id_cotizacion);
            $lockedAccess = AccesoCliente::query()->lockForUpdate()->findOrFail($access->id_acceso);

            if ($locked->hasClientDecision()) {
                return ['status' => 'already_decided', 'cotizacion' => $locked];
            }
            if ($lockedAccess->revocado || (int) $lockedAccess->id_cotizacion !== (int) $locked->id_cotizacion || ! $locked->isAwaitingDecision()) {
                return ['status' => 'unavailable', 'cotizacion' => $locked];
            }
            if ($lockedAccess->isExpired() || $locked->isPastDueDate()) {
                return ['status' => 'expired', 'cotizacion' => $locked];
            }
            if ((int) $input['version'] !== (int) $locked->version || (int) $input['revision'] !== (int) $locked->revision) {
                throw ValidationException::withMessages([
                    'decisiones' => 'La información que tienes abierta no coincide con la cotización vigente. Recarga la página para ver la versión actual.',
                ]);
            }
            if (! $locked->hasValidFingerprint()) {
                return ['status' => 'integrity', 'cotizacion' => $locked];
            }

            $details = $locked->detalles()->lockForUpdate()->orderBy('id_detalle')->get()->keyBy('id_detalle');
            $decisions = $this->normalizeKeys($input['decisiones']);
            $riskConfirmations = $this->normalizeKeys($input['confirmar_riesgo'] ?? []);

            if (array_diff(array_keys($decisions), $details->keys()->all()) !== []) {
                throw ValidationException::withMessages([
                    'decisiones' => 'Uno de los conceptos enviados no pertenece a esta cotización. Recarga la página.',
                ]);
            }

            $errors = [];
            foreach ($details as $detailId => $detail) {
                $choice = $decisions[$detailId] ?? null;
                if (! in_array($choice, ['aceptado', 'rechazado'], true)) {
                    $errors["decisiones.$detailId"] = 'Indica si autorizas o rechazas «'.$detail->descripcion.'».';
                } elseif ($choice === 'rechazado' && $detail->isNecessary() && ($riskConfirmations[$detailId] ?? null) !== '1') {
                    $errors["confirmar_riesgo.$detailId"] = 'Para rechazar «'.$detail->descripcion.'» confirma que leíste la advertencia.';
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $now = now();
            $acceptedCents = 0;
            $accepted = [];
            $rejected = [];
            $necessaryRejected = [];

            foreach ($details as $detailId => $detail) {
                $choice = $decisions[$detailId];
                $detail->forceFill([
                    'decision_cliente' => $choice,
                    'riesgo_confirmado_en' => $choice === 'rechazado' && $detail->isNecessary() ? $now : null,
                ])->save();

                if ($choice === 'aceptado') {
                    $acceptedCents += DetalleCotizacion::decimalToHundredths((string) $detail->importe);
                    $accepted[] = $detailId;
                } else {
                    $rejected[] = $detailId;
                    if ($detail->isNecessary()) {
                        $necessaryRejected[] = $detailId;
                    }
                }
            }

            $status = match (true) {
                $rejected === [] => 'aprobada',
                $accepted === [] => 'rechazada',
                default => 'parcialmente_aprobada',
            };

            $locked->forceFill([
                'estado' => $status,
                'decision_cliente' => ['aprobada' => 'aprobada', 'parcialmente_aprobada' => 'parcial', 'rechazada' => 'rechazada'][$status],
                'fecha_decision' => $now,
                'importe_autorizado' => DetalleCotizacion::formatHundredths($acceptedCents),
            ])->save();
            $lockedAccess->forceFill(['ultimo_acceso' => $now])->save();

            EventoCotizacion::record($locked, $accepted === [] ? 'rechazada' : 'autorizada', [
                'resultado' => $status,
                'version' => $locked->version,
                'aceptados' => $accepted,
                'rechazados' => $rejected,
                'necesarios_rechazados' => $necessaryRejected,
                'importe_autorizado' => $locked->importe_autorizado,
                'total_cotizado' => (string) $locked->total,
            ], null, $lockedAccess->id_acceso, $request);

            // Trabajos de seguimiento: uno por concepto aceptado de esta versión exacta, en la misma transacción.
            if ($accepted !== []) {
                $this->repairWorks->generateForQuotation($locked);
            }

            return ['status' => 'recorded', 'cotizacion' => $locked];
        });

        if ($result['status'] === 'expired') {
            $this->dispatch->expireIfOverdue($result['cotizacion'], $request, 'portal');
        }
        if ($result['status'] === 'integrity') {
            $this->dispatch->recordIntegrityFailure($result['cotizacion'], $access->id_acceso, 'autorizacion', null, $request);
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeKeys(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_numeric($key) && is_scalar($value)) {
                $normalized[(int) $key] = (string) $value;
            }
        }

        return $normalized;
    }
}
