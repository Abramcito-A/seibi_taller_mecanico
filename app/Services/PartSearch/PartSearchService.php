<?php

namespace App\Services\PartSearch;

use App\Models\Vehiculo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PartSearchService
{
    public function __construct(private readonly SerpApiOfferNormalizer $normalizer) {}

    /**
     * @return array{status: string, query: string, offers: array<int, array<string, mixed>>, message?: string}
     */
    public function search(Vehiculo $vehicle, string $partName, string $mode = 'compatible'): array
    {
        $apiKey = trim((string) config('services.serpapi.api_key'));
        $query = $this->buildQuery($vehicle, $partName, $mode);
        if ($apiKey === '') {
            return [
                'status' => 'not_configured',
                'query' => $query,
                'offers' => [],
                'message' => 'La búsqueda comercial no está configurada. Puede continuar con la captura manual.',
            ];
        }

        try {
            $response = Http::connectTimeout(4)
                ->timeout(12)
                ->retry(2, 250, function (ConnectionException|RequestException $exception): bool {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->serverError() || $exception->response->status() === 429));
                })
                ->get('https://serpapi.com/search.json', [
                    'engine' => 'google_shopping',
                    'api_key' => $apiKey,
                    'q' => $query,
                    'gl' => 'mx',
                    'hl' => 'es-419',
                    'location' => config('services.serpapi.location', 'Mexico'),
                    'num' => 20,
                ]);
        } catch (ConnectionException) {
            return [
                'status' => 'provider_error',
                'query' => $query,
                'offers' => [],
                'message' => 'No fue posible conectar con el proveedor comercial. Puede continuar con la captura manual.',
            ];
        } catch (RequestException) {
            return [
                'status' => 'provider_error',
                'query' => $query,
                'offers' => [],
                'message' => 'El proveedor comercial respondió con un error. Puede continuar con la captura manual.',
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => 'provider_error',
                'query' => $query,
                'offers' => [],
                'message' => 'El proveedor comercial respondió con un error. Puede continuar con la captura manual.',
            ];
        }

        $payload = $response->json();
        $shoppingResults = is_array($payload['shopping_results'] ?? null)
            ? $payload['shopping_results']
            : [];
        $offers = $this->normalizer->normalize($shoppingResults);

        return [
            'status' => $offers === [] ? 'no_results' : 'success',
            'query' => $query,
            'offers' => $offers,
            'message' => $offers === [] ? 'No se encontraron ofertas para esta búsqueda.' : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     * @return array<int, array<string, mixed>>
     */
    public function prepareOffersForSelection(int $userId, int $orderId, Vehiculo $vehicle, array $offers, string $query): array
    {
        $searchId = (string) Str::uuid();
        $prepared = [];
        $storedOffers = [];

        foreach ($offers as $offer) {
            $offerId = (string) Str::uuid();
            $storedOffers[$offerId] = $offer;
            $preparedOffer = $offer;
            $preparedOffer['search_id'] = $searchId;
            $preparedOffer['offer_id'] = $offerId;
            $prepared[] = $preparedOffer;
        }

        Cache::put(
            $this->searchCacheKey($userId, $searchId),
            [
                'user_id' => $userId,
                'order_id' => $orderId,
                'vehicle_id' => $vehicle->getKey(),
                'query' => $query,
                'offers' => $storedOffers,
            ],
            now()->addMinutes(30)
        );

        return $prepared;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedOffer(int $userId, int $orderId, int $vehicleId, string $searchId, string $offerId): ?array
    {
        $search = Cache::get($this->searchCacheKey($userId, $searchId));
        if (! is_array($search)) {
            return null;
        }

        if (($search['user_id'] ?? null) !== $userId
            || ($search['order_id'] ?? null) !== $orderId
            || ($search['vehicle_id'] ?? null) !== $vehicleId
        ) {
            return null;
        }

        $offers = $search['offers'] ?? null;

        return is_array($offers) && is_array($offers[$offerId] ?? null)
            ? $offers[$offerId]
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enrichCachedOffer(
        int $userId,
        int $orderId,
        int $vehicleId,
        string $searchId,
        string $offerId
    ): ?array {
        $offer = $this->getCachedOffer($userId, $orderId, $vehicleId, $searchId, $offerId);
        if ($offer === null) {
            return null;
        }

        $cacheKey = 'seibi:part-details:'.$userId.':'.$offerId;

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($offer): array {
            $productId = $offer['provider_product_id'] ?? null;
            $pageToken = $offer['page_token'] ?? null;
            if (! is_string($productId) && ! is_string($pageToken)) {
                return $offer;
            }

            $apiKey = trim((string) config('services.serpapi.api_key'));
            if ($apiKey === '') {
                return $offer;
            }

            $parameters = [
                'engine' => 'google_product',
                'api_key' => $apiKey,
                'gl' => 'mx',
                'hl' => 'es-419',
                'location' => config('services.serpapi.location', 'Mexico'),
            ];
            if (is_string($productId) && $productId !== '') {
                $parameters['product_id'] = $productId;
            } elseif (is_string($pageToken) && $pageToken !== '') {
                $parameters['page_token'] = $pageToken;
            }

            try {
                $response = Http::connectTimeout(4)
                    ->timeout(12)
                    ->get('https://serpapi.com/search.json', $parameters);
            } catch (ConnectionException|RequestException) {
                return $offer;
            }

            if (! $response->successful()) {
                return $offer;
            }

            $product = $response->json('product_results');
            if (! is_array($product)) {
                return $offer;
            }

            $brand = $this->stringOrNull($product['brand'] ?? null);
            if ($brand !== null) {
                $offer['brand'] = $brand;
                $offer['technical_details']['brand'] = $brand;
            }

            $features = $product['about_the_product']['features'] ?? null;
            if (is_array($features)) {
                foreach ($features as $feature) {
                    if (! is_array($feature)) {
                        continue;
                    }
                    $title = strtolower(trim((string) ($feature['title'] ?? $feature['name'] ?? '')));
                    $value = $feature['value'] ?? $feature['description'] ?? null;
                    if (! in_array($title, ['brand', 'manufacturer', 'mpn', 'manufacturer part number', 'part number', 'marca', 'numero de parte', 'número de parte'], true)) {
                        $valueText = $this->stringOrNull(is_array($value) ? ($value[0] ?? null) : $value);
                        if ($valueText !== null) {
                            $technicalFeatures = is_array($offer['technical_details']['features'] ?? null)
                                ? $offer['technical_details']['features']
                                : [];
                            $technicalFeatures[] = ['title' => $this->stringOrNull($feature['title'] ?? $feature['name'] ?? null) ?? $title, 'value' => $valueText];
                            $offer['technical_details']['features'] = $technicalFeatures;
                        }

                        continue;
                    }
                    $value = $this->stringOrNull(is_array($value) ? ($value[0] ?? null) : $value);
                    if ($value === null) {
                        continue;
                    }
                    if (in_array($title, ['brand', 'manufacturer', 'marca'], true)) {
                        $offer['brand'] = $offer['brand'] ?? $value;
                        $offer['technical_details']['brand'] = $value;
                    } else {
                        $offer['part_number'] = $value;
                        $offer['technical_details']['part_number'] = $value;
                    }
                }
            }

            $stores = $product['stores'] ?? null;
            if (is_array($stores)) {
                foreach ($stores as $store) {
                    if (! is_array($store)) {
                        continue;
                    }
                    $storeName = strtolower(trim((string) ($store['seller'] ?? $store['name'] ?? $store['source'] ?? '')));
                    $currentStore = strtolower(trim((string) ($offer['store_name'] ?? '')));
                    if ($storeName !== '' && $currentStore !== '' && $storeName !== $currentStore) {
                        continue;
                    }
                    $directUrl = $this->httpUrlOrNull($store['link'] ?? null);
                    if ($directUrl !== null) {
                        $offer['url'] = $directUrl;
                        break;
                    }
                }
            }

            $technicalDetails = is_array($offer['technical_details'] ?? null)
                ? $offer['technical_details']
                : [];

            foreach (['specifications', 'specs', 'product_specifications'] as $key) {
                if (! is_array($product[$key] ?? null)) {
                    continue;
                }
                $technicalDetails['specifications'] = $this->normalizeTechnicalItems($product[$key]);
                break;
            }
            $aboutProduct = is_array($product['about_the_product'] ?? null)
                ? $product['about_the_product']
                : [];
            foreach (['specifications', 'specs'] as $key) {
                if (is_array($aboutProduct[$key] ?? null)) {
                    $technicalDetails['specifications'] = $this->normalizeTechnicalItems($aboutProduct[$key]);
                    break;
                }
            }

            foreach (['compatibility', 'vehicle_compatibility', 'compatible_vehicles'] as $key) {
                if (! is_array($product[$key] ?? null) && ! is_string($product[$key] ?? null)) {
                    continue;
                }
                $compatibility = $product[$key];
                if (is_string($compatibility)) {
                    $technicalDetails['compatibility'] = [$compatibility];
                } elseif (is_array($compatibility)) {
                    $technicalDetails['compatibility'] = $this->normalizeTechnicalItems($compatibility);
                }
                break;
            }

            $offer['technical_details'] = $technicalDetails;

            if ($this->stringOrNull($product['image'] ?? null) !== null) {
                $imageUrl = $this->httpUrlOrNull($product['image']);
                if ($imageUrl !== null) {
                    $offer['image_url'] = $imageUrl;
                }
            }

            return $offer;
        });
    }

    private function searchCacheKey(int $userId, string $searchId): string
    {
        return 'seibi:part-search:'.$userId.':'.$searchId;
    }

    /**
     * @param  array<int, mixed>|array<string, mixed>  $items
     * @return array<int, array<string, string>|string>
     */
    private function normalizeTechnicalItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $key => $item) {
            if (is_string($item)) {
                $value = trim($item);
                if ($value !== '') {
                    $normalized[] = is_string($key) && trim((string) $key) !== ''
                        ? ['title' => trim((string) $key), 'value' => $value]
                        : $value;
                }

                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $title = $this->stringOrNull($item['title'] ?? $item['name'] ?? $item['label'] ?? null);
            $value = $this->stringOrNull($item['value'] ?? $item['description'] ?? $item['content'] ?? null);
            if ($title !== null || $value !== null) {
                $normalized[] = array_filter([
                    'title' => $title,
                    'value' => $value,
                ], fn (?string $entry): bool => $entry !== null);
            }
        }

        return $normalized;
    }

    public function buildQuery(Vehiculo $vehicle, string $partName, string $mode = 'compatible'): string
    {
        $partName = trim($partName);
        if ($mode === 'general') {
            return $partName;
        }

        $parts = array_filter([
            $partName,
            trim((string) $vehicle->marca),
            trim((string) $vehicle->modelo),
            $vehicle->anio !== null ? (string) $vehicle->anio : null,
            trim((string) ($vehicle->version ?? '')),
        ]);

        return implode(' ', $parts);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function httpUrlOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return $value !== null && filter_var($value, FILTER_VALIDATE_URL)
            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $value
            : null;
    }
}
