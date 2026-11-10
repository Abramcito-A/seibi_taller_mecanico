<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
use App\Models\OrdenServicio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CotizacionController extends Controller
{
    private const WORK_TYPES = ['diagnostico', 'mantenimiento', 'reparacion', 'otro'];

    private const DETAIL_TYPES = ['pieza', 'servicio', 'mano_obra', 'otro'];

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
        $validated = $this->validateQuotation($request);

        $cotizacion = DB::transaction(function () use ($request, $orden, $validated): Cotizacion {
            $totals = $this->calculateTotals($validated['detalles']);
            $cotizacion = Cotizacion::query()->create([
                'id_orden' => $orden->id_orden,
                'id_usuario' => $request->user()->getKey(),
                'folio' => $this->generateFolio(),
                'titulo' => $validated['titulo'],
                'tipo' => $validated['tipo'],
                'descripcion' => $validated['descripcion'] ?? null,
                'subtotal' => $this->formatMoney($totals['subtotal']),
                'total' => $this->formatMoney($totals['total']),
                'estado' => 'borrador',
                'decision_cliente' => 'pendiente',
                'observaciones' => $validated['observaciones'] ?? null,
            ]);

            $this->storeDetails($cotizacion, $validated['detalles']);

            return $cotizacion;
        });

        return redirect()->route('cotizaciones.show', [$orden, $cotizacion])
            ->with('success', 'Borrador de cotización guardado correctamente.');
    }

    public function show(OrdenServicio $orden, Cotizacion $cotizacion): View
    {
        abort_unless((int) $cotizacion->id_orden === (int) $orden->id_orden, 404);
        $orden->load(['vehiculo.cliente']);
        $cotizacion->load('detalles');

        return view('cotizaciones.show', compact('orden', 'cotizacion'));
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
            'detailTypes' => self::DETAIL_TYPES,
        ]);
    }

    public function update(Request $request, OrdenServicio $orden, Cotizacion $cotizacion): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
        abort_unless((int) $cotizacion->id_orden === (int) $orden->id_orden, 404);
        $this->ensureDraftEditable($cotizacion);
        $validated = $this->validateQuotation($request);

        DB::transaction(function () use ($validated, $cotizacion): void {
            $locked = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id_cotizacion);
            abort_unless($locked->estado === 'borrador', 403);

            $totals = $this->calculateTotals($validated['detalles']);
            $locked->update([
                'titulo' => $validated['titulo'],
                'tipo' => $validated['tipo'],
                'descripcion' => $validated['descripcion'] ?? null,
                'subtotal' => $this->formatMoney($totals['subtotal']),
                'total' => $this->formatMoney($totals['total']),
                'observaciones' => $validated['observaciones'] ?? null,
            ]);

            DetalleCotizacion::query()
                ->where('id_cotizacion', $locked->id_cotizacion)
                ->delete();
            $this->storeDetails($locked, $validated['detalles']);
        });

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

    private function validateQuotation(Request $request): array
    {
        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'tipo' => ['required', Rule::in(self::WORK_TYPES)],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'detalles' => ['required', 'array', 'min:1', 'max:100'],
            'detalles.*.descripcion' => ['required', 'string', 'max:255'],
            'detalles.*.tipo_concepto' => ['required', Rule::in(self::DETAIL_TYPES)],
            'detalles.*.cantidad' => ['required', 'numeric', 'min:0.01', 'max:9999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'detalles.*.precio_unitario' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'detalles.*.moneda' => ['nullable', Rule::in(['MXN'])],
            'detalles.*.tienda_origen' => ['nullable', 'string', 'max:150'],
            'detalles.*.marca' => ['nullable', 'string', 'max:100'],
            'detalles.*.numero_parte' => ['nullable', 'string', 'max:100'],
            'detalles.*.url_origen' => ['nullable', 'url:http,https', 'max:2000'],
            'detalles.*.imagen_url' => ['nullable', 'url:http,https', 'max:2000'],
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
     * @param  array<int, array<string, mixed>>  $details
     * @return array{subtotal: int, total: int}
     */
    private function calculateTotals(array $details): array
    {
        $subtotalCents = 0;

        foreach ($details as $detail) {
            $quantityHundredths = $this->decimalToHundredths((string) $detail['cantidad']);
            $priceHundredths = $this->decimalToHundredths((string) $detail['precio_unitario']);
            $product = $quantityHundredths * $priceHundredths;
            $lineCents = intdiv($product, 100);
            if (($product % 100) >= 50) {
                $lineCents++;
            }
            $subtotalCents += $lineCents;
        }

        return ['subtotal' => $subtotalCents, 'total' => $subtotalCents];
    }

    private function decimalToHundredths(string $value): int
    {
        $value = trim($value);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function formatMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function storeDetails(Cotizacion $cotizacion, array $details): void
    {
        foreach ($details as $detail) {
            $quantityHundredths = $this->decimalToHundredths((string) $detail['cantidad']);
            $priceHundredths = $this->decimalToHundredths((string) $detail['precio_unitario']);
            $quantity = $this->formatMoney($quantityHundredths);
            $price = $this->formatMoney($priceHundredths);
            $product = $quantityHundredths * $priceHundredths;
            $lineCents = intdiv($product, 100);
            if (($product % 100) >= 50) {
                $lineCents++;
            }

            $cotizacion->detalles()->create([
                'tipo_concepto' => $detail['tipo_concepto'],
                'descripcion' => $detail['descripcion'],
                'cantidad' => $quantity,
                'precio_unitario' => $price,
                'importe' => $this->formatMoney($lineCents),
                'moneda' => $detail['moneda'] ?? 'MXN',
                'tienda_origen' => $detail['tienda_origen'] ?? null,
                'marca' => $detail['marca'] ?? null,
                'numero_parte' => $detail['numero_parte'] ?? null,
                'url_origen' => $detail['url_origen'] ?? null,
                'imagen_url' => $detail['imagen_url'] ?? null,
            ]);
        }
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
        abort_unless($cotizacion->estado === 'borrador', 403, 'Solo los borradores pueden modificarse.');
    }
}
