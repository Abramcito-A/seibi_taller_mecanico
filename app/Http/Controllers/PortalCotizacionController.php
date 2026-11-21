<?php

namespace App\Http\Controllers;

use App\Models\AccesoCliente;
use App\Models\Cotizacion;
use App\Models\Evidencia;
use App\Services\Cotizaciones\QuotationDecisionService;
use App\Services\Reparaciones\RepairWorkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal público del cliente: consulta y autorización de una cotización mediante el enlace privado.
 * Ninguna ruta recibe IDs; todo se deriva del acceso asociado al token.
 */
class PortalCotizacionController extends Controller
{
    public function __construct(
        private QuotationDecisionService $decisions,
        private RepairWorkService $repairWorks,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $resolution = $this->decisions->resolveAccess($token, $request);
        if ($resolution['status'] !== QuotationDecisionService::ACCESS_VALID) {
            return $this->unavailable($resolution['status']);
        }

        $access = $resolution['access'];
        $cotizacion = $resolution['cotizacion'];
        $this->decisions->registerView($access, $cotizacion, $request);

        // Tras una autorización total o parcial el mismo enlace muestra el seguimiento (solo lectura) y la cotización.
        if ($cotizacion->isAuthorized()) {
            return response()->view('portal.cotizaciones.seguimiento', [
                ...$this->viewData($token, $access, $cotizacion),
                'tracking' => $this->repairWorks->portalTracking($access, $cotizacion),
            ]);
        }

        return response()->view(
            $cotizacion->hasClientDecision() ? 'portal.cotizaciones.resultado' : 'portal.cotizaciones.show',
            $this->viewData($token, $access, $cotizacion),
        );
    }

    /**
     * Fotografía publicada del seguimiento. Se identifica por el UUID aleatorio del archivo (nunca por su id) y solo
     * se entrega si pertenece a la orden del token, a una actualización publicada de un trabajo de la cotización del
     * token (o a la revisión final de la orden) y está marcada como visible para el cliente.
     */
    public function photo(Request $request, string $token, string $reference): Response
    {
        $resolution = $this->decisions->resolveAccess($token, $request);
        if ($resolution['status'] !== QuotationDecisionService::ACCESS_VALID || ! $resolution['cotizacion']->isAuthorized()) {
            abort(404);
        }
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $reference) !== 1) {
            abort(404);
        }

        $cotizacion = $resolution['cotizacion'];
        $ordenId = (int) $cotizacion->id_orden;

        $evidence = Evidencia::query()
            ->where('id_orden', $ordenId)
            ->where('etapa', Evidencia::ETAPA_REPARACION)
            ->where('visible_cliente', true)
            ->whereNotNull('id_actualizacion')
            ->where('ruta_archivo', 'like', 'ordenes/'.$ordenId.'/reparacion/%/'.$reference.'.%')
            ->whereHas('actualizacion', function ($updates) use ($ordenId, $cotizacion): void {
                $updates->where('id_orden', $ordenId)
                    ->where('visible_cliente', true)
                    ->whereNotNull('fecha_publicacion')
                    ->where(function ($scope) use ($cotizacion): void {
                        $scope->whereHas('trabajo', fn ($works) => $works->where('id_cotizacion', $cotizacion->id_cotizacion))
                            ->orWhere(fn ($orderLevel) => $orderLevel->whereNull('id_trabajo')->where('tipo', 'revision_final'));
                    });
            })
            ->first();

        $pathPattern = '/^ordenes\/'.$ordenId.'\/reparacion\/\d+\/'.$reference.'\.(jpg|jpeg|png|webp)$/';
        if ($evidence === null || preg_match($pathPattern, $evidence->ruta_archivo, $matches) !== 1) {
            abort(404);
        }

        $disk = Storage::disk('private');
        abort_unless($disk->exists($evidence->ruta_archivo), 404);

        return $disk->response($evidence->ruta_archivo, 'fotografia-'.$reference.'.'.$matches[1], [
            'Content-Type' => $matches[1] === 'png' ? 'image/png' : ($matches[1] === 'webp' ? 'image/webp' : 'image/jpeg'),
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function decide(Request $request, string $token): Response|RedirectResponse
    {
        $resolution = $this->decisions->resolveAccess($token, $request);
        if ($resolution['status'] !== QuotationDecisionService::ACCESS_VALID) {
            return $this->unavailable($resolution['status']);
        }

        $cotizacion = $resolution['cotizacion'];
        if ($cotizacion->hasClientDecision()) {
            return redirect()->route('portal.cotizaciones.show', ['token' => $token]);
        }

        $validator = Validator::make($request->all(), [
            'decisiones' => ['required', 'array'],
            'decisiones.*' => ['required', Rule::in(['aceptado', 'rechazado'])],
            'confirmar_riesgo' => ['nullable', 'array'],
            'confirmar_riesgo.*' => ['nullable', Rule::in(['1'])],
            'version' => ['required', 'integer'],
            'revision' => ['required', 'integer'],
            'confirmacion_final' => ['accepted'],
        ], [
            'decisiones.required' => 'Indica qué conceptos autorizas y cuáles rechazas.',
            'decisiones.*.required' => 'Indica si autorizas o rechazas cada concepto.',
            'decisiones.*.in' => 'Indica si autorizas o rechazas cada concepto.',
            'confirmacion_final.accepted' => 'Confirma que revisaste el resumen antes de registrar tu decisión.',
        ]);

        // Los errores regresan explícitamente a la ruta del portal: la URL con el token no se guarda en la sesión.
        if ($validator->fails()) {
            return $this->backToQuotation($token, $request, $validator->errors()->toArray());
        }

        try {
            $result = $this->decisions->decide($resolution['access'], $validator->validated(), $request);
        } catch (ValidationException $exception) {
            return $this->backToQuotation($token, $request, $exception->errors());
        }

        return match ($result['status']) {
            'recorded' => redirect()->route('portal.cotizaciones.show', ['token' => $token])
                ->with('portal_notice', 'Tu decisión quedó registrada. Gracias; el taller la revisará para continuar.'),
            'already_decided' => redirect()->route('portal.cotizaciones.show', ['token' => $token]),
            'expired' => $this->unavailable(QuotationDecisionService::ACCESS_EXPIRED),
            'integrity' => $this->unavailable(QuotationDecisionService::ACCESS_INTEGRITY),
            default => $this->unavailable(QuotationDecisionService::ACCESS_REPLACED),
        };
    }

    /**
     * Datos visibles para el cliente. Nunca incluye comercio proveedor, precio de compra, enlaces ni datos de la API.
     *
     * @return array<string, mixed>
     */
    private function viewData(string $token, AccesoCliente $access, Cotizacion $cotizacion): array
    {
        $vehiculo = $cotizacion->orden->vehiculo;

        return [
            'token' => $token,
            'workshopName' => (string) config('seibi.workshop_name'),
            'cotizacion' => $cotizacion,
            'ordenFolio' => $cotizacion->orden->folio,
            'vehiculo' => $vehiculo,
            'clienteNombre' => trim($vehiculo->cliente->nombre.' '.$vehiculo->cliente->apellido),
            'detalles' => $cotizacion->detalles,
            'expiresAt' => $access->fecha_expiracion,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function backToQuotation(string $token, Request $request, array $errors): RedirectResponse
    {
        return redirect()
            ->route('portal.cotizaciones.show', ['token' => $token])
            ->withErrors($errors)
            ->withInput($request->only(['decisiones', 'confirmar_riesgo']));
    }

    private function unavailable(string $status): Response
    {
        [$code, $title, $message] = match ($status) {
            QuotationDecisionService::ACCESS_TRACKING_ENDED => [410, 'El periodo de consulta terminó', 'La orden de servicio concluyó y el plazo para consultar el seguimiento de la reparación terminó. Si necesitas información, comunícate con el taller.'],
            QuotationDecisionService::ACCESS_EXPIRED => [410, 'El enlace venció', 'La vigencia de esta cotización terminó. Comunícate con el taller para recibir una cotización vigente.'],
            QuotationDecisionService::ACCESS_SUPERSEDED => [410, 'Hay una versión más reciente', 'El taller preparó una versión actualizada de esta cotización. Consulta el correo más reciente que recibiste o comunícate con el taller.'],
            QuotationDecisionService::ACCESS_REPLACED => [410, 'Este enlace ya no está disponible', 'El taller generó un enlace nuevo para esta cotización. Usa el correo más reciente o comunícate con el taller.'],
            QuotationDecisionService::ACCESS_INTEGRITY => [409, 'El taller debe revisar esta cotización', 'Detectamos una diferencia en la información de esta cotización y, por seguridad, no se puede consultar ni autorizar. Comunícate con el taller para que la revise.'],
            default => [404, 'Enlace no válido', 'No encontramos una cotización con este enlace. Verifica que lo copiaste completo o comunícate con el taller.'],
        };

        return response()->view('portal.acceso-invalido', ['title' => $title, 'message' => $message], $code);
    }
}
