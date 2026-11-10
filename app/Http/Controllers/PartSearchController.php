<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use App\Services\PartSearch\PartSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartSearchController extends Controller
{
    public function __construct(private readonly PartSearchService $partSearchService) {}

    public function search(Request $request, OrdenServicio $orden): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:150'],
            'mode' => ['sometimes', 'string', Rule::in(['compatible', 'general'])],
        ]);

        $orden->loadMissing('vehiculo');

        $mode = $validated['mode'] ?? 'compatible';
        $result = $this->partSearchService->search($orden->vehiculo, $validated['q'], $mode);
        if (($result['status'] ?? null) === 'success') {
            $result['offers'] = $this->partSearchService->prepareOffersForSelection(
                (int) $request->user()->getKey(),
                (int) $orden->getKey(),
                $orden->vehiculo,
                $result['offers'],
                $result['query']
            );
        }
        $result['original_term'] = $validated['q'];
        $result['mode'] = $mode;

        return response()->json($result);
    }

    public function offerDetails(Request $request, OrdenServicio $orden): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);

        $validated = $request->validate([
            'search_id' => ['required', 'uuid'],
            'offer_id' => ['required', 'uuid'],
        ]);

        $orden->loadMissing('vehiculo');
        $offer = $this->partSearchService->enrichCachedOffer(
            (int) $request->user()->getKey(),
            (int) $orden->getKey(),
            (int) $orden->vehiculo->getKey(),
            $validated['search_id'],
            $validated['offer_id']
        );

        if ($offer === null) {
            return response()->json([
                'message' => 'La oferta no corresponde a esta búsqueda, orden o vehículo.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'offer' => $offer,
        ]);
    }

    public function addOffer(Request $request, OrdenServicio $orden, Cotizacion $cotizacion): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('mecanico'), 403);
        abort_unless((int) $cotizacion->id_orden === (int) $orden->id_orden, 404);
        abort_unless($cotizacion->estado === 'borrador', 403, 'Solo los borradores pueden modificarse.');

        $request->merge([
            'idempotency_key' => $request->header('X-Idempotency-Key')
                ?? $request->input('idempotency_key'),
        ]);

        $validated = $request->validate([
            'search_id' => ['required', 'uuid'],
            'offer_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
        ]);

        $orden->loadMissing('vehiculo');
        $offer = $this->partSearchService->enrichCachedOffer(
            (int) $request->user()->getKey(),
            (int) $orden->getKey(),
            (int) $orden->vehiculo->getKey(),
            $validated['search_id'],
            $validated['offer_id']
        );

        if ($offer === null) {
            return response()->json([
                'message' => 'La oferta no corresponde a esta búsqueda, orden o vehículo.',
            ], 404);
        }

        $price = $offer['price_numeric'] ?? null;
        $currency = $offer['currency'] ?? null;
        if (! is_numeric($price) || (float) $price < 0 || $currency !== 'MXN') {
            return response()->json([
                'message' => 'La oferta no tiene un precio numérico MXN confiable. Captura la pieza manualmente.',
            ], 422);
        }

        $lockKey = 'seibi:part-add:'.$request->user()->getKey().':'.$orden->getKey().':'.$cotizacion->getKey().':'.$validated['idempotency_key'];
        $detailId = null;
        $created = false;

        Cache::lock($lockKey, 10)->block(5, function () use (&$detailId, &$created, $cotizacion, $offer, $lockKey): void {
            $cachedDetailId = Cache::get($lockKey);
            if ($cachedDetailId !== null) {
                $detailId = (int) $cachedDetailId;

                return;
            }

            $detailId = DB::transaction(function () use ($cotizacion, $offer): int {
                $locked = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id_cotizacion);
                abort_unless($locked->estado === 'borrador', 403);

                $priceHundredths = $this->decimalToHundredths(number_format((float) $offer['price_numeric'], 2, '.', ''));
                $detail = $locked->detalles()->create([
                    'tipo_concepto' => 'pieza',
                    'descripcion' => $offer['title'],
                    'cantidad' => '1.00',
                    'precio_unitario' => $this->formatMoney($priceHundredths),
                    'importe' => $this->formatMoney($priceHundredths),
                    'moneda' => 'MXN',
                    'tienda_origen' => $offer['store_name'] ?? null,
                    'marca' => $offer['brand'] ?? null,
                    'numero_parte' => $offer['part_number'] ?? null,
                    'url_origen' => $offer['url'] ?? null,
                    'imagen_url' => $offer['image_url'] ?? null,
                ]);

                $subtotalCents = 0;
                foreach ($locked->detalles()->get() as $detail) {
                    $quantityHundredths = $this->decimalToHundredths((string) $detail->cantidad);
                    $priceHundredths = $this->decimalToHundredths((string) $detail->precio_unitario);
                    $product = $quantityHundredths * $priceHundredths;
                    $lineCents = intdiv($product, 100);
                    if (($product % 100) >= 50) {
                        $lineCents++;
                    }
                    $subtotalCents += $lineCents;
                }
                $subtotal = number_format($subtotalCents / 100, 2, '.', '');
                $locked->update([
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                ]);

                return (int) $detail->getKey();
            });

            Cache::put($lockKey, $detailId, now()->addHour());
            $created = true;
        });

        if ($created) {
            $request->session()->flash('success', 'Refacción agregada correctamente a la cotización.');
        }

        return response()->json([
            'message' => $created
                ? 'Refacción agregada correctamente a la cotización'
                : 'La refacción ya estaba agregada por esta operación.',
            'detail_id' => $detailId,
            'redirect_url' => route('cotizaciones.edit', [$orden, $cotizacion]),
            'idempotent' => ! $created,
        ]);
    }

    private function decimalToHundredths(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function formatMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
