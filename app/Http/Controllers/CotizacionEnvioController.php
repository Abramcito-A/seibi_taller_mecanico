<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\EventoCotizacion;
use App\Models\OrdenServicio;
use App\Services\Cotizaciones\QuotationDispatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Acciones del mecánico sobre el envío de cotizaciones al cliente. No modifica el estado de la orden de servicio.
 */
class CotizacionEnvioController extends Controller
{
    public function __construct(private QuotationDispatchService $dispatch) {}

    public function send(Request $request, OrdenServicio $orden, Cotizacion $cotizacion): RedirectResponse
    {
        $this->authorizeQuotation($orden, $cotizacion);
        $result = $this->dispatch->send($cotizacion, $request->user(), $request);

        return $this->deliveryRedirect($orden, $cotizacion, $result);
    }

    public function resend(Request $request, OrdenServicio $orden, Cotizacion $cotizacion): RedirectResponse
    {
        $this->authorizeQuotation($orden, $cotizacion);
        $result = $this->dispatch->resend($cotizacion, $request->user(), $request);

        return $this->deliveryRedirect($orden, $cotizacion, $result);
    }

    public function createVersion(Request $request, OrdenServicio $orden, Cotizacion $cotizacion): RedirectResponse
    {
        $this->authorizeQuotation($orden, $cotizacion);
        $result = $this->dispatch->createVersion($cotizacion, $request->user(), $request);

        return redirect()->route('cotizaciones.edit', [$orden, $result['cotizacion']])
            ->with($result['created'] ? 'success' : 'info', $result['created']
                ? 'Se creó la versión '.$result['cotizacion']->version.' como borrador. La versión anterior se conserva y se sustituirá cuando envíes esta.'
                : 'Ya existía un borrador de la versión '.$result['cotizacion']->version.'; se abrió para continuar editándolo.');
    }

    /**
     * Registra qué hará el taller tras conocer la decisión del cliente. Solo se guarda en la bitácora de la cotización.
     */
    public function followUp(Request $request, OrdenServicio $orden, Cotizacion $cotizacion): RedirectResponse
    {
        $this->authorizeQuotation($orden, $cotizacion);

        $validated = $request->validate([
            'accion' => ['required', Rule::in(array_keys(EventoCotizacion::FOLLOW_UP_ACTIONS))],
            'nota' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'accion.required' => 'Selecciona la acción que seguirá el taller.',
            'nota.required' => 'Escribe una nota que explique la decisión del taller.',
            'nota.min' => 'La nota debe explicar brevemente la decisión.',
        ]);

        DB::transaction(function () use ($request, $cotizacion, $validated): void {
            $locked = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id_cotizacion);
            if (! $locked->hasClientDecision() || $locked->estado === 'sustituida') {
                throw ValidationException::withMessages(['accion' => 'El seguimiento se registra después de que el cliente decide sobre la versión vigente.']);
            }

            $necessaryRejected = $locked->detalles()->where('prioridad', 'necesario')->where('decision_cliente', 'rechazado')->pluck('descripcion')->all();
            $hasAccepted = $locked->detalles()->where('decision_cliente', 'aceptado')->exists();
            if ($validated['accion'] === 'continuar_autorizados' && ! $hasAccepted) {
                throw ValidationException::withMessages(['accion' => 'El cliente no autorizó ningún concepto; no hay trabajos autorizados para continuar.']);
            }

            EventoCotizacion::record($locked, 'seguimiento_registrado', [
                'accion' => $validated['accion'],
                'nota' => $validated['nota'],
                'resultado_cliente' => $locked->estado,
                'necesarios_rechazados' => $necessaryRejected,
            ], $request->user()->getKey(), null, $request);
        });

        return redirect()->route('cotizaciones.show', [$orden, $cotizacion])
            ->with('success', 'Seguimiento registrado: '.EventoCotizacion::FOLLOW_UP_ACTIONS[$validated['accion']].'.');
    }

    private function authorizeQuotation(OrdenServicio $orden, Cotizacion $cotizacion): void
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
        abort_unless((int) $cotizacion->id_orden === (int) $orden->id_orden, 404);
    }

    /**
     * @param  array{status: 'sent'|'simulated'|'failed'|'blocked', message: string}  $result
     */
    private function deliveryRedirect(OrdenServicio $orden, Cotizacion $cotizacion, array $result): RedirectResponse
    {
        $flashKey = match ($result['status']) {
            'sent' => 'success',
            'simulated' => 'info',
            default => 'error',
        };

        return redirect()->route('cotizaciones.show', [$orden, $cotizacion])->with($flashKey, $result['message']);
    }
}
