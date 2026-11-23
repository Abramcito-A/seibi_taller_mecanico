<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
use App\Models\EventoCotizacion;
use App\Models\OrdenServicio;
use App\Services\Mail\MailDeliveryGuard;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class CotizacionController extends Controller
{
    private const WORK_TYPES = ['diagnostico', 'mantenimiento', 'reparacion', 'otro'];

    public function index(OrdenServicio $orden): View
    {
        $orden->load(['vehiculo.cliente']);
        $cotizaciones = $orden->cotizaciones()
            ->with('detalles')
            ->latest('fecha_creacion')
            ->latest('id_cotizacion')
            ->get();

        return view('cotizaciones.index', compact('orden', 'cotizaciones'));
    }

    public function create(OrdenServicio $orden): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
        if (! $orden->diagnosticos()->exists()) {
            return $this->diagnosisRequired($orden);
        }
        $orden->load(['vehiculo.cliente', 'diagnosticos.usuario']);

        $cotizacion = DB::transaction(function () use ($orden): Cotizacion {
            return Cotizacion::query()->create([
                'id_orden' => $orden->id_orden,
                'id_usuario' => auth()->id(),
                'folio' => $this->generateFolio(),
                'titulo' => 'Nueva cotización',
                'tipo' => 'reparacion',
                'subtotal' => '0.00',
                'total' => '0.00',
                'estado' => 'borrador',
                'decision_cliente' => 'pendiente',
            ]);
        });

        return redirect()->route('cotizaciones.edit', [$orden, $cotizacion]);
    }

    public function store(Request $request, OrdenServicio $orden): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
        if (! $orden->diagnosticos()->exists()) {
            return $this->diagnosisRequired($orden);
        }
        $validated = $this->validateQuotation($request);

        $cotizacion = DB::transaction(function () use ($request, $orden, $validated): Cotizacion {
            $cotizacion = Cotizacion::query()->create([
                'id_orden' => $orden->id_orden,
                'id_usuario' => $request->user()->getKey(),
                'folio' => $this->generateFolio(),
                'titulo' => $validated['titulo'],
                'tipo' => $validated['tipo'],
                'descripcion' => $validated['descripcion'] ?? null,
                'subtotal' => '0.00',
                'total' => '0.00',
                'estado' => 'borrador',
                'decision_cliente' => 'pendiente',
                'observaciones' => $validated['observaciones'] ?? null,
            ]);

            $this->syncDetails($cotizacion, $validated['detalles']);
            $cotizacion->recalculateTotals();
            $cotizacion->save();

            return $cotizacion;
        });

        return redirect()->route('cotizaciones.show', [$orden, $cotizacion])
            ->with('success', 'Borrador de cotización guardado correctamente.');
    }

    public function show(OrdenServicio $orden, Cotizacion $cotizacion, MailDeliveryGuard $mailGuard): View
    {
        abort_unless((int) $cotizacion->id_orden === (int) $orden->id_orden, 404);
        $orden->load(['vehiculo.cliente']);
        $cotizacion->load([
            'detalles' => fn ($query) => $query->orderBy('id_detalle'),
            'origen:id_cotizacion,folio,version,estado',
            'siguienteVersion:id_cotizacion,id_cotizacion_origen,folio,version,estado',
            'eventos' => fn ($query) => $query->with('usuario:id,name')->latest('fecha')->latest('id_evento')->limit(100),
        ]);

        $latestAccess = $cotizacion->accesos()->latest('id_acceso')->first();
        $lastDelivery = $cotizacion->eventos->first(fn (EventoCotizacion $event): bool => in_array($event->tipo, ['enviada', 'reenviada', 'envio_aceptado', 'envio_fallido'], true));

        return view('cotizaciones.show', [
            'orden' => $orden,
            'cotizacion' => $cotizacion,
            'latestAccess' => $latestAccess,
            'lastDelivery' => $lastDelivery,
            'mailStatus' => $mailGuard->status(),
            'integrityValid' => $cotizacion->huella_contenido === null ? null : $cotizacion->hasValidFingerprint(),
        ]);
    }

    public function edit(OrdenServicio $orden, Cotizacion $cotizacion): View
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
        abort_unless((int) $cotizacion->id_orden === (int) $orden->id_orden, 404);
        $this->ensureDraftEditable($cotizacion);
        $orden->load(['vehiculo.cliente', 'diagnosticos.usuario']);
        $cotizacion->load('detalles');

        return view('cotizaciones.edit', [
            'orden' => $orden,
            'cotizacion' => $cotizacion,
            'workTypes' => self::WORK_TYPES,
            'detailTypes' => DetalleCotizacion::CONCEPT_TYPES,
        ]);
    }

    public function update(Request $request, OrdenServicio $orden, Cotizacion $cotizacion): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
        abort_unless((int) $cotizacion->id_orden === (int) $orden->id_orden, 404);
        $this->ensureDraftEditable($cotizacion);
        $validated = $this->validateQuotation($request);

        $submissionKey = isset($validated['submission_token'])
            ? 'seibi:cotizacion-update:'.$cotizacion->getKey().':'.$validated['submission_token']
            : null;
        if ($submissionKey !== null && ! Cache::add($submissionKey, true, now()->addHour())) {
            return redirect()->route('cotizaciones.show', [$orden, $cotizacion])
                ->with('info', 'Estos cambios ya se habían guardado. Se ignoró el envío repetido del formulario.');
        }

        try {
            DB::transaction(function () use ($validated, $cotizacion): void {
                $locked = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id_cotizacion);
                abort_unless($locked->isEditableDraft(), 403, 'Solo los borradores pueden modificarse.');

                if (isset($validated['revision']) && (int) $validated['revision'] !== $locked->revision) {
                    throw ValidationException::withMessages([
                        'revision' => 'La cotización cambió después de abrir este formulario (por ejemplo, se agregó una refacción en otra pestaña). Recarga la página para continuar con la versión actual.',
                    ]);
                }

                $locked->fill([
                    'titulo' => $validated['titulo'],
                    'tipo' => $validated['tipo'],
                    'descripcion' => $validated['descripcion'] ?? null,
                    'observaciones' => $validated['observaciones'] ?? null,
                ]);
                $this->syncDetails($locked, $validated['detalles']);
                $locked->recalculateTotals();
                $locked->revision++;
                $locked->save();
            });
        } catch (Throwable $exception) {
            if ($submissionKey !== null) {
                Cache::forget($submissionKey);
            }

            throw $exception;
        }

        return redirect()->route('cotizaciones.show', [$orden, $cotizacion])
            ->with('success', 'Borrador de cotización actualizado correctamente.');
    }

    public function destroy(OrdenServicio $orden, Cotizacion $cotizacion): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
        abort_unless((int) $cotizacion->id_orden === (int) $orden->id_orden, 404);
        $this->ensureDraftEditable($cotizacion);

        $cotizacion->delete();

        return redirect()->route('cotizaciones.index', $orden)
            ->with('success', 'Borrador de cotización eliminado.');
    }

    /**
     * @return array{titulo: string, tipo: string, descripcion?: ?string, observaciones?: ?string, revision?: int, submission_token?: ?string, detalles: array<int, array<string, mixed>>}
     */
    private function validateQuotation(Request $request): array
    {
        $safeUrl = function (string $attribute, mixed $value, Closure $fail): void {
            if (! DetalleCotizacion::isSafeExternalUrl($value)) {
                $fail('Debe ser una dirección web válida que inicie con http:// o https://.');
            }
        };

        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'tipo' => ['required', Rule::in(self::WORK_TYPES)],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'revision' => ['sometimes', 'integer', 'min:1'],
            'submission_token' => ['nullable', 'uuid'],
            'detalles' => ['required', 'array', 'min:1', 'max:100'],
            'detalles.*.id_detalle' => ['nullable', 'integer', 'min:1'],
            'detalles.*.descripcion' => ['required', 'string', 'max:255'],
            'detalles.*.tipo_concepto' => ['required', Rule::in(DetalleCotizacion::CONCEPT_TYPES)],
            'detalles.*.prioridad' => ['nullable', Rule::in(array_keys(DetalleCotizacion::PRIORITIES))],
            'detalles.*.cantidad' => ['required', 'numeric', 'min:0.01', 'max:9999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'detalles.*.precio_unitario' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'detalles.*.moneda' => ['nullable', Rule::in(['MXN'])],
            'detalles.*.tienda_origen' => ['nullable', 'string', 'max:150'],
            'detalles.*.marca' => ['nullable', 'string', 'max:100'],
            'detalles.*.fabricante' => ['nullable', 'string', 'max:150'],
            'detalles.*.numero_parte' => ['nullable', 'string', 'max:100'],
            'detalles.*.url_origen' => ['nullable', 'string', 'max:2000', $safeUrl],
            'detalles.*.imagen_url' => ['nullable', 'string', 'max:2000', $safeUrl],
        ], [
            'detalles.required' => 'Agrega al menos un concepto a la cotización.',
            'detalles.min' => 'Agrega al menos un concepto a la cotización.',
            'detalles.*.descripcion.required' => 'La descripción del concepto es obligatoria.',
            'detalles.*.cantidad.min' => 'La cantidad debe ser mayor que cero.',
            'detalles.*.precio_unitario.min' => 'El precio unitario no puede ser negativo.',
        ]);

        $quantityErrors = [];
        foreach ($validated['detalles'] as $index => $detail) {
            $quantity = trim((string) $detail['cantidad']);
            $type = $detail['tipo_concepto'];
            if ($type === 'mano_obra') {
                if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $quantity) || (float) $quantity < 0.01) {
                    $quantityErrors["detalles.$index.cantidad"] = 'Las horas deben ser decimales positivos con máximo dos decimales.';
                }
            } elseif (! preg_match('/^\d+(?:\.0{1,2})?$/', $quantity) || (float) $quantity < 1) {
                $quantityErrors["detalles.$index.cantidad"] = 'Esta cantidad debe ser un número entero positivo.';
            }
        }

        if ($quantityErrors !== []) {
            throw ValidationException::withMessages($quantityErrors);
        }

        return $validated;
    }

    /**
     * Sincroniza los conceptos por id_detalle: actualiza los existentes, crea los nuevos y elimina los que
     * el mecánico quitó. La instantánea del proveedor de los conceptos existentes se conserva desde la base
     * de datos; nunca se toma del formulario.
     *
     * @param  array<int, array<string, mixed>>  $details
     */
    private function syncDetails(Cotizacion $cotizacion, array $details): void
    {
        $existing = $cotizacion->detalles()->lockForUpdate()->get()->keyBy('id_detalle');
        $keptIds = [];

        foreach ($details as $index => $detail) {
            $attributes = $this->editableDetailAttributes($detail);
            $detailId = isset($detail['id_detalle']) ? (int) $detail['id_detalle'] : null;

            if ($detailId === null) {
                $cotizacion->detalles()->create($attributes + ['moneda' => 'MXN']);

                continue;
            }

            $model = $existing->get($detailId);
            if ($model === null || in_array($detailId, $keptIds, true)) {
                throw ValidationException::withMessages([
                    "detalles.$index.id_detalle" => 'El concepto no pertenece a esta cotización o está repetido. Recarga la página.',
                ]);
            }

            $model->update($attributes);
            $keptIds[] = $detailId;
        }

        $removedIds = $existing->keys()->diff($keptIds)->all();
        if ($removedIds !== []) {
            DetalleCotizacion::query()->whereIn('id_detalle', $removedIds)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function editableDetailAttributes(array $detail): array
    {
        $quantityHundredths = DetalleCotizacion::decimalToHundredths((string) $detail['cantidad']);
        $priceHundredths = DetalleCotizacion::decimalToHundredths((string) $detail['precio_unitario']);
        $quantity = DetalleCotizacion::formatHundredths($quantityHundredths);
        $price = DetalleCotizacion::formatHundredths($priceHundredths);

        return [
            'tipo_concepto' => $detail['tipo_concepto'],
            'prioridad' => $detail['prioridad'] ?? null,
            'descripcion' => $detail['descripcion'],
            'cantidad' => $quantity,
            'precio_unitario' => $price,
            'importe' => DetalleCotizacion::formatHundredths(DetalleCotizacion::lineAmountCents($quantity, $price)),
            'tienda_origen' => $detail['tienda_origen'] ?? null,
            'marca' => $detail['marca'] ?? null,
            'fabricante' => $detail['fabricante'] ?? null,
            'numero_parte' => $detail['numero_parte'] ?? null,
            'url_origen' => $detail['url_origen'] ?? null,
            'imagen_url' => $detail['imagen_url'] ?? null,
        ];
    }

    /**
     * Una cotización se basa en los hallazgos del mecánico: sin diagnóstico registrado no se puede cotizar.
     */
    private function diagnosisRequired(OrdenServicio $orden): RedirectResponse
    {
        return redirect()->route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos'])
            ->with('error', 'Registra al menos un hallazgo de diagnóstico antes de crear una cotización.');
    }

    private function generateFolio(): string
    {
        do {
            $folio = 'COT-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Cotizacion::query()->where('folio', $folio)->exists());

        return $folio;
    }

    private function ensureDraftEditable(Cotizacion $cotizacion): void
    {
        abort_unless($cotizacion->isEditableDraft(), 403, 'Solo los borradores pueden modificarse.');
    }
}
