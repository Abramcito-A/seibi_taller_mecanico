<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
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
        abort_unless($cotizacion->isEditableDraft(), 403, 'Solo los borradores pueden modificarse.');

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
        $offer = $this->partSearchService->resolveOfferForQuotation(
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
                abort_unless($locked->isEditableDraft(), 403);

                $price = DetalleCotizacion::formatHundredths(
                    DetalleCotizacion::decimalToHundredths(number_format((float) $offer['price_numeric'], 2, '.', ''))
                );
                $detail = $locked->detalles()->create([
                    'tipo_concepto' => 'pieza',
                    'descripcion' => $this->limit($offer['title'], 255),
                    'cantidad' => '1.00',
                    'precio_unitario' => $price,
                    'precio_proveedor' => $price,
                    'moneda_original' => $offer['original_currency'] ?? null,
                    'precio_moneda_original' => $offer['original_price'] ?? null,
                    'titulo_proveedor' => $this->limit($offer['title'] ?? null, 255),
                    'importe' => $price,
                    'moneda' => 'MXN',
                    'tienda_origen' => $this->limit($offer['store_name'] ?? null, 150),
                    'marca' => $this->limit($offer['brand'] ?? null, 100),
                    'fabricante' => $this->limit($offer['manufacturer'] ?? null, 150),
                    'numero_parte' => $this->limit($offer['part_number'] ?? null, 100),
                    'url_origen' => $offer['url'] ?? null,
                    'enlace_estado' => ($offer['url'] ?? null) !== null ? 'proveedor' : 'sin_enlace',
                    'enlace_motivo' => ($offer['url'] ?? null) !== null ? null : $this->missingLinkReason($offer),
                    'enlace_verificado_en' => now(),
                    'url_shopping' => $offer['shopping_url'] ?? null,
                    'imagen_url' => $offer['image_url'] ?? null,
                    'proveedor' => $this->limit($offer['provider'] ?? null, 50),
                    'proveedor_motor' => $this->limit($offer['provider_engine'] ?? null, 50),
                    'proveedor_producto_id' => $this->limit($offer['provider_product_id'] ?? null, 100),
                    'proveedor_token' => is_string($offer['page_token'] ?? null) ? $offer['page_token'] : null,
                    'detalles_tecnicos' => DetalleCotizacion::sanitizeTechnicalDetails($offer['technical_details'] ?? null),
                ]);

                $locked->recalculateTotals();
                $locked->revision++;
                $locked->save();

                return (int) $detail->getKey();
            });

            Cache::put($lockKey, $detailId, now()->addHour());
            $created = true;
        });

        $cotizacion->refresh();
        $detail = $cotizacion->detalles()->find($detailId);

        return response()->json([
            'message' => $created
                ? 'Refacción agregada correctamente a la cotización'
                : 'La refacción ya estaba agregada por esta operación.',
            'detail_id' => $detailId,
            'revision' => $cotizacion->revision,
            'subtotal' => $cotizacion->subtotal,
            'total' => $cotizacion->total,
            'row_html' => $detail !== null
                ? view('cotizaciones._detail-row', [
                    'detail' => $detail->only(['id_detalle', ...DetalleCotizacion::EDITABLE_FIELDS]),
                    'index' => '__INDEX__',
                    'detailTypes' => DetalleCotizacion::CONCEPT_TYPES,
                ])->render()
                : null,
            'redirect_url' => route('cotizaciones.edit', [$orden, $cotizacion]),
            'idempotent' => ! $created,
        ]);
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function missingLinkReason(array $offer): string
    {
        return match ($offer['details_status'] ?? null) {
            'provider_error' => 'El proveedor no respondió al consultar el detalle de la oferta.',
            'not_available' => 'La oferta no trae identificador para consultar su detalle.',
            default => 'El detalle del producto no muestra exactamente el mismo comercio y precio.',
        };
    }

    private function limit(mixed $value, int $length): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $length) : null;
    }
}
