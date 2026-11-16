<?php

namespace App\Services\PartSearch;

use App\Models\DetalleCotizacion;
use App\Models\Vehiculo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PartSearchService
{
    public const DETAILS_ENGINE = 'google_product';

    /**
     * Diferencia máxima admitida al recuperar un enlace, solo cuando hay evidencia de conversión de moneda.
     */
    public const CURRENCY_CONVERSION_TOLERANCE = 0.01;

    private const ENDPOINT = 'https://serpapi.com/search.json';

    /**
     * Títulos de atributos (normalizados sin acentos) que el proveedor usa para marca, fabricante y número de parte.
     */
    private const BRAND_LABELS = ['marca', 'brand'];

    private const MANUFACTURER_LABELS = ['fabricante', 'manufacturer'];

    private const PART_NUMBER_LABELS = [
        'mpn', 'manufacturer part number', 'part number', 'numero de parte', 'numero de pieza',
        'no. de parte', 'num. de parte', 'numero de parte del fabricante',
    ];

    /**
     * Calificadores de dominio para términos ambiguos en búsqueda general. Con la muestra real de 2026-11-16,
     * "aceite automotriz" devolvió 40/40 aceites de motor y "banda para auto" 39/40 bandas automotrices,
     * mientras que "banda automotriz" y "aceite para auto" no devolvieron resultados; por eso se prueban en orden.
     */
    private const GENERAL_QUALIFIERS = ['automotriz', 'para auto'];

    public function __construct(
        private readonly SerpApiOfferNormalizer $normalizer,
        private readonly AutomotiveRelevanceClassifier $relevance,
        private readonly VehicleCompatibilityEvaluator $compatibility,
    ) {}

    /**
     * @return array{status: string, query: string, attempted_queries: array<int, string>, offers: array<int, array<string, mixed>>, summary: array{received: int, automotive: int, uncertain: int, excluded: int}, message?: ?string}
     */
    public function search(Vehiculo $vehicle, string $partName, string $mode = 'compatible'): array
    {
        $assessment = $this->relevance->assessQuery($partName);
        $candidates = $this->queryCandidates($vehicle, $partName, $mode, $assessment['status']);
        $emptySummary = ['received' => 0, 'automotive' => 0, 'uncertain' => 0, 'excluded' => 0];

        if ($assessment['status'] === AutomotiveRelevanceClassifier::EXCLUDED) {
            return [
                'status' => 'not_automotive',
                'query' => trim($partName),
                'attempted_queries' => [],
                'offers' => [],
                'summary' => $emptySummary,
                'message' => 'La búsqueda "'.trim($partName).'" no corresponde a refacciones ni productos automotrices, por lo que no se consultó al proveedor. Puedes capturar el concepto manualmente.',
            ];
        }

        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return [
                'status' => 'not_configured',
                'query' => $candidates[0],
                'attempted_queries' => [],
                'offers' => [],
                'summary' => $emptySummary,
                'message' => 'La búsqueda comercial no está configurada. Puede continuar con la captura manual.',
            ];
        }

        $attempted = [];
        $shoppingResults = [];
        $executedQuery = $candidates[0];
        foreach ($candidates as $candidate) {
            $attempted[] = $candidate;
            $response = $this->requestShopping($apiKey, $candidate);
            if ($response === null) {
                return [
                    'status' => 'provider_error',
                    'query' => $candidate,
                    'attempted_queries' => $attempted,
                    'offers' => [],
                    'summary' => $emptySummary,
                    'message' => 'El proveedor comercial no respondió correctamente. Puede continuar con la captura manual.',
                ];
            }

            if ($response !== []) {
                $shoppingResults = $response;
                $executedQuery = $candidate;
                break;
            }
        }

        $offers = $this->normalizer->normalize($shoppingResults);
        $classified = $this->classifyOffers($offers, $vehicle, $partName, $mode);
        $summary = [
            'received' => count($offers),
            'automotive' => count(array_filter($classified, fn (array $offer): bool => $offer['relevance']['status'] === AutomotiveRelevanceClassifier::AUTOMOTIVE)),
            'uncertain' => count(array_filter($classified, fn (array $offer): bool => $offer['relevance']['status'] === AutomotiveRelevanceClassifier::UNCERTAIN)),
            'excluded' => count($offers) - count($classified),
        ];

        return [
            'status' => match (true) {
                $offers === [] => 'no_results',
                $classified === [] => 'no_relevant_results',
                default => 'success',
            },
            'query' => $executedQuery,
            'attempted_queries' => $attempted,
            'offers' => $classified,
            'summary' => $summary,
            'message' => match (true) {
                $offers === [] => 'No se encontraron ofertas para esta búsqueda.',
                $classified === [] => 'El proveedor solo devolvió productos ajenos a la mecánica automotriz. Especifica la pieza o consumible (p. ej. "banda de accesorios", "aceite 5W-30").',
                default => null,
            },
        ];
    }

    /**
     * Consultas a intentar en orden; la siguiente solo se ejecuta si la anterior no devolvió resultados.
     *
     * - Compatible: término + marca + modelo + año + versión depurada; respaldo sin año ni versión.
     * - General: el término tal cual y, si no hay resultados, con el calificador "automotriz". Si el término es
     *   ambiguo ("aceite", "banda") se acota primero con un calificador de dominio, nunca con datos del vehículo,
     *   y como último recurso se consulta el término literal.
     *
     * @return array<int, string>
     */
    public function queryCandidates(Vehiculo $vehicle, string $partName, string $mode, ?string $termStatus = null): array
    {
        $partName = trim((string) preg_replace('/\s+/', ' ', $partName));
        $termStatus ??= $this->relevance->assessQuery($partName)['status'];

        if ($mode === 'general') {
            if ($termStatus !== 'ambiguous') {
                // Google responde vacío de forma intermitente a términos que antes funcionaban ("anticongelante",
                // "aceite sintético 5W-30"); el calificador de dominio solo se usa si el término literal no devuelve nada.
                return [$partName, $partName.' '.self::GENERAL_QUALIFIERS[0]];
            }

            return [
                ...array_map(fn (string $qualifier): string => $partName.' '.$qualifier, self::GENERAL_QUALIFIERS),
                $partName,
            ];
        }

        $make = trim((string) $vehicle->marca);
        $model = trim((string) $vehicle->modelo);
        $full = implode(' ', array_filter([
            $partName,
            $make,
            $model,
            $vehicle->anio !== null ? (string) $vehicle->anio : null,
            $this->cleanVersion($vehicle->version),
        ]));
        $relaxed = implode(' ', array_filter([$partName, $make, $model]));

        return array_values(array_unique([$full, $relaxed]));
    }

    /**
     * Intenta recuperar la URL directa de un concepto guardado sin ella, consultando el detalle del mismo
     * producto (product_id del proveedor) y exigiendo el mismo comercio y el mismo precio de la oferta original.
     * Nunca inventa enlaces ni toma el de otro vendedor; no escribe en la base de datos.
     *
     * @return array{status: string, url: ?string, reason: string}
     */
    public function recoverMerchantUrl(DetalleCotizacion $detail): array
    {
        if (DetalleCotizacion::isSafeExternalUrl($detail->url_origen)) {
            return ['status' => 'has_url', 'url' => $detail->url_origen, 'reason' => 'Ya tiene una URL directa válida; no se reemplaza.'];
        }

        $productId = $this->stringOrNull($detail->proveedor_producto_id);
        $storeName = $this->stringOrNull($detail->tienda_origen);
        $price = $detail->precio_proveedor ?? $detail->precio_unitario;
        if (($productId === null && $this->stringOrNull($detail->proveedor_token) === null) || $storeName === null) {
            return ['status' => 'not_recoverable', 'url' => null, 'reason' => 'Sin identificador del producto o sin comercio registrado: no es posible verificar la oferta original.'];
        }

        if ($this->apiKey() === '') {
            return ['status' => 'provider_error', 'url' => null, 'reason' => 'La búsqueda comercial no está configurada.'];
        }

        $response = $this->requestProduct([
            'page_token' => $this->stringOrNull($detail->proveedor_token),
            'provider_product_id' => $productId,
        ]);
        if ($response['status'] === 'error') {
            return ['status' => 'provider_error', 'url' => null, 'reason' => 'El proveedor no respondió; puede reintentarse más tarde.'];
        }
        if ($response['status'] === 'empty') {
            return ['status' => 'not_recoverable', 'url' => null, 'reason' => 'El proveedor ya no devuelve resultados para este producto'.($detail->proveedor_token ? '.' : ' (no se guardó el token de la oferta original).')];
        }

        $price = (float) $price;
        $candidates = $this->sameMerchantStores($storeName, $response['product']['stores'] ?? null);
        $exact = array_values(array_filter($candidates, function (array $candidate) use ($price): bool {
            foreach ([$candidate['price'], $candidate['total']] as $candidatePrice) {
                if ($candidatePrice !== null && abs($candidatePrice - $price) < 0.005) {
                    return true;
                }
            }

            return false;
        }));

        if (count($exact) === 1) {
            return ['status' => 'recovered', 'url' => $exact[0]['url'], 'reason' => 'El comercio "'.$storeName.'" ofrece el producto al mismo precio ($'.number_format($price, 2).').'];
        }
        if (count($exact) > 1) {
            return ['status' => 'not_recoverable', 'url' => null, 'reason' => 'Hay varias publicaciones de "'.$storeName.'" al mismo precio; no se puede saber cuál es la original.'];
        }

        return $this->matchCurrencyConversion($detail, $storeName, $price, $candidates)
            ?? ['status' => 'not_recoverable', 'url' => null, 'reason' => 'El proveedor no muestra exactamente una oferta de "'.$storeName.'" a $'.number_format($price, 2).' (pudo cambiar el precio o retirarse la publicación).'];
    }

    /**
     * Tolerancia de hasta 1% solo si todo lo siguiente se demuestra: la oferta original se publicó en moneda
     * extranjera (alternative_price guardado), la tienda recuperada declara la misma moneda (alt_currency) con un
     * monto que no cambió más de 1%, el título de la publicación es el mismo y existe un único candidato.
     *
     * @param  array<int, array{url: string, price: ?float, total: ?float, title: ?string, alt_currency: ?string, alt_amount: ?float}>  $candidates
     * @return array{status: string, url: string, reason: string}|null
     */
    private function matchCurrencyConversion(DetalleCotizacion $detail, string $storeName, float $price, array $candidates): ?array
    {
        $currency = $this->stringOrNull($detail->moneda_original);
        $originalAmount = $detail->precio_moneda_original !== null ? (float) $detail->precio_moneda_original : null;
        $publicationTitle = $this->normalizer->normalizeLabel($detail->titulo_proveedor);
        if ($currency === null || $currency === 'MXN' || $originalAmount === null || $publicationTitle === null || $price <= 0) {
            return null;
        }

        $matches = array_values(array_filter($candidates, function (array $candidate) use ($currency, $originalAmount, $publicationTitle, $price): bool {
            return $candidate['price'] !== null
                && $candidate['alt_currency'] === $currency
                && $candidate['alt_amount'] !== null
                && abs($candidate['alt_amount'] - $originalAmount) / $originalAmount <= self::CURRENCY_CONVERSION_TOLERANCE + 1e-9
                && $this->normalizer->normalizeLabel($candidate['title']) === $publicationTitle
                && abs($candidate['price'] - $price) / $price <= self::CURRENCY_CONVERSION_TOLERANCE + 1e-9;
        }));

        if (count($matches) !== 1) {
            return null;
        }

        $match = $matches[0];

        return [
            'status' => 'recovered_conversion',
            'url' => $match['url'],
            'reason' => sprintf(
                'Misma publicación de "%s" en %s: %s %s → %s; MXN %s → %s (%.2f%%, tipo de cambio).',
                $storeName, $currency, $currency, number_format($originalAmount, 2), number_format($match['alt_amount'], 2),
                number_format($price, 2), number_format($match['price'], 2), abs($match['price'] - $price) / $price * 100,
            ),
        ];
    }

    /**
     * Ofertas del mismo comercio con enlace directo válido, con su precio, título y moneda declarada.
     *
     * @return array<int, array{url: string, price: ?float, total: ?float, title: ?string, alt_currency: ?string, alt_amount: ?float}>
     */
    private function sameMerchantStores(string $storeName, mixed $stores): array
    {
        $storeName = $this->normalizer->normalizeLabel($storeName);
        $candidates = [];

        foreach (is_array($stores) ? $stores : [] as $store) {
            $url = is_array($store) ? $this->normalizer->merchantUrlOrNull($store['link'] ?? null) : null;
            $storePrice = is_array($store) ? $this->normalizer->numberOrNull($store['extracted_price'] ?? null) : null;
            $storeTotal = is_array($store) ? $this->normalizer->numberOrNull($store['extracted_total'] ?? null) : null;
            if ($url === null || ($storePrice === null && $storeTotal === null) || $this->normalizer->normalizeLabel($store['name'] ?? null) !== $storeName) {
                continue;
            }

            $altCurrency = null;
            $altAmount = null;
            if (is_string($store['alt_currency'] ?? null) && preg_match('/([A-Z]{3})\s*([\d,]+(?:\.\d+)?)/', $store['alt_currency'], $parts) === 1) {
                $altCurrency = $parts[1];
                $altAmount = (float) str_replace(',', '', $parts[2]);
            }

            $candidates[$url] = [
                'url' => $url,
                'price' => $storePrice,
                'total' => $storeTotal,
                'title' => is_string($store['title'] ?? null) ? $store['title'] : null,
                'alt_currency' => $altCurrency,
                'alt_amount' => $altAmount,
            ];
        }

        return array_values($candidates);
    }

    /**
     * Consultas al proveedor registradas en un día, por motor: llamadas reales (consumen crédito) y respuestas de caché.
     *
     * @return array<string, array{provider: int, cache: int}>
     */
    public function usageFor(string $date): array
    {
        $usage = [];
        foreach ([SerpApiOfferNormalizer::SEARCH_ENGINE, self::DETAILS_ENGINE] as $engine) {
            $usage[$engine] = [
                'provider' => (int) Cache::get($this->usageKey($date, $engine, 'provider'), 0),
                'cache' => (int) Cache::get($this->usageKey($date, $engine, 'cache'), 0),
            ];
        }

        return $usage;
    }

    public function buildQuery(Vehiculo $vehicle, string $partName, string $mode = 'compatible'): string
    {
        return $this->queryCandidates($vehicle, $partName, $mode)[0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     * @return array<int, array<string, mixed>>
     */
    private function classifyOffers(array $offers, Vehiculo $vehicle, string $partName, string $mode): array
    {
        $compatibilityRank = [
            VehicleCompatibilityEvaluator::MENTIONED => 0,
            VehicleCompatibilityEvaluator::UNKNOWN => 1,
            VehicleCompatibilityEvaluator::CONFLICT => 2,
        ];
        $kept = [];

        foreach ($offers as $position => $offer) {
            $offer['relevance'] = $this->relevance->classifyOffer($offer, $partName, (string) $vehicle->modelo);
            if ($offer['relevance']['status'] === AutomotiveRelevanceClassifier::EXCLUDED) {
                continue;
            }

            $offer['compatibility'] = $mode === 'compatible'
                ? $this->compatibility->evaluate($vehicle, (string) $offer['title'])
                : null;
            $offer['provider_position'] = $position + 1;
            $kept[] = $offer;
        }

        usort($kept, function (array $left, array $right) use ($compatibilityRank): int {
            $relevanceOrder = ($left['relevance']['status'] === AutomotiveRelevanceClassifier::AUTOMOTIVE ? 0 : 1)
                <=> ($right['relevance']['status'] === AutomotiveRelevanceClassifier::AUTOMOTIVE ? 0 : 1);
            $compatibilityOrder = ($compatibilityRank[$left['compatibility']['status'] ?? 'unknown'] ?? 1)
                <=> ($compatibilityRank[$right['compatibility']['status'] ?? 'unknown'] ?? 1);

            return $relevanceOrder ?: $compatibilityOrder ?: $left['provider_position'] <=> $right['provider_position'];
        });

        return $kept;
    }

    /**
     * De la versión solo se conserva la cilindrada (p. ej. "1.6", "2.0L"), que identifica la pieza.
     * En pruebas reales, etiquetas como "4 puertas" o "LE" hicieron que Google Shopping no devolviera resultados.
     */
    private function cleanVersion(?string $version): ?string
    {
        preg_match_all('/(?<![0-9.])[1-8]\.\d(?![0-9])/', (string) $version, $matches);

        return $matches[0] === [] ? null : implode(' ', array_unique($matches[0]));
    }

    /**
     * @return array<int, mixed>|null Resultados de shopping_results o null si el proveedor falló.
     */
    private function requestShopping(string $apiKey, string $query): ?array
    {
        $parameters = [
            'engine' => SerpApiOfferNormalizer::SEARCH_ENGINE,
            'q' => $query,
            'gl' => 'mx',
            'hl' => 'es-419',
            'location' => config('services.serpapi.location', 'Mexico'),
            'num' => 20,
        ];
        $cacheKey = $this->providerCacheKey($parameters);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $this->recordUsage(SerpApiOfferNormalizer::SEARCH_ENGINE, fromCache: true);

            return $cached;
        }

        $this->recordUsage(SerpApiOfferNormalizer::SEARCH_ENGINE, fromCache: false);
        try {
            $response = $this->http()->get(self::ENDPOINT, $parameters + ['api_key' => $apiKey]);
        } catch (ConnectionException|RequestException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $results = $response->json('shopping_results');
        $results = is_array($results) ? $results : [];

        // Google a veces responde sin resultados de forma intermitente; solo se guardan respuestas con ofertas
        // para que un vacío transitorio no bloquee la búsqueda durante horas.
        if ($results !== []) {
            Cache::put($cacheKey, $results, now()->addMinutes((int) config('services.serpapi.search_cache_minutes', 360)));
        }

        return $results;
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
     * Devuelve la oferta enriquecida con Google Product (marca, atributos y enlace directo de la oferta exacta).
     *
     * Solo se almacenan en caché las respuestas exitosas, para que un fallo temporal del proveedor no impida
     * obtener el enlace en un intento posterior.
     *
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

        $cacheKey = $this->detailsCacheKey($userId, $offerId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->stringOrNull($offer['page_token'] ?? null) === null
            && $this->stringOrNull($offer['provider_product_id'] ?? null) === null
        ) {
            return $offer + ['details_status' => 'not_available'];
        }

        $product = $this->fetchProductResults($offer);
        if ($product === null) {
            return $offer + ['details_status' => 'provider_error'];
        }

        $enriched = $this->applyProductResults($offer, $product);
        Cache::put($cacheKey, $enriched, now()->addMinutes(30));

        return $enriched;
    }

    /**
     * Obtiene la oferta que se guardará en la cotización. Si el resultado de búsqueda ya trae una URL directa
     * del comercio no se hace una consulta adicional, salvo que los detalles ya estén en caché.
     *
     * @return array<string, mixed>|null
     */
    public function resolveOfferForQuotation(
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

        $cached = Cache::get($this->detailsCacheKey($userId, $offerId));
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->normalizer->merchantUrlOrNull($offer['url'] ?? null) !== null) {
            return $offer;
        }

        return $this->enrichCachedOffer($userId, $orderId, $vehicleId, $searchId, $offerId);
    }

    /**
     * Consulta google_product priorizando page_token, que identifica la oferta exacta seleccionada.
     * Con product_id Google devuelve las ofertas del catálogo y puede omitir la del comercio seleccionado.
     *
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>|null
     */
    private function fetchProductResults(array $offer): ?array
    {
        return $this->requestProduct($offer)['product'];
    }

    /**
     * Consulta google_product priorizando page_token y distingue tres resultados: producto encontrado (ok),
     * el proveedor respondió sin resultados para ese identificador (empty) y falla de red o del proveedor (error).
     * Las respuestas ok y empty se guardan en caché para no repetir el crédito.
     *
     * @param  array<string, mixed>  $offer
     * @return array{status: string, product: ?array<string, mixed>}
     */
    private function requestProduct(array $offer): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return ['status' => 'error', 'product' => null];
        }

        $parameters = [
            'engine' => self::DETAILS_ENGINE,
            'gl' => 'mx',
            'hl' => 'es-419',
        ];
        $pageToken = $this->stringOrNull($offer['page_token'] ?? null);
        if ($pageToken !== null) {
            $parameters['page_token'] = $pageToken;
        } else {
            $parameters['product_id'] = $this->stringOrNull($offer['provider_product_id'] ?? null);
        }

        $cacheKey = $this->providerCacheKey($parameters);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['status'])) {
            $this->recordUsage(self::DETAILS_ENGINE, fromCache: true);

            return $cached;
        }

        $this->recordUsage(self::DETAILS_ENGINE, fromCache: false);
        try {
            $response = $this->http()->get(self::ENDPOINT, $parameters + ['api_key' => $apiKey]);
        } catch (ConnectionException|RequestException) {
            return ['status' => 'error', 'product' => null];
        }

        if (! $response->successful()) {
            return ['status' => 'error', 'product' => null];
        }

        $product = $response->json('product_results');
        $result = is_array($product)
            ? ['status' => 'ok', 'product' => $product]
            : ['status' => is_string($response->json('error')) ? 'empty' : 'error', 'product' => null];

        if ($result['status'] !== 'error') {
            Cache::put($cacheKey, $result, now()->addMinutes((int) config('services.serpapi.details_cache_minutes', 60)));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function applyProductResults(array $offer, array $product): array
    {
        $brand = $this->stringOrNull($product['brand'] ?? null);
        $manufacturer = null;
        $partNumber = null;
        $features = [];

        foreach ($this->attributeItems($product) as ['title' => $title, 'value' => $value]) {
            $label = $this->normalizer->normalizeLabel($title);
            if (in_array($label, self::BRAND_LABELS, true)) {
                $brand ??= $value;
            } elseif (in_array($label, self::MANUFACTURER_LABELS, true)) {
                $manufacturer ??= $value;
            } elseif (in_array($label, self::PART_NUMBER_LABELS, true)) {
                $partNumber ??= $value;
            } else {
                $features[$label.'|'.$this->normalizer->normalizeLabel($value)] = ['title' => $title, 'value' => $value];
            }
        }

        $offer['brand'] = $brand ?? ($offer['brand'] ?? null);
        $offer['manufacturer'] = $manufacturer ?? ($offer['manufacturer'] ?? null);
        $offer['part_number'] = $partNumber ?? ($offer['part_number'] ?? null);

        $store = $this->matchSelectedStore($offer, $product['stores'] ?? null);
        $offer['url'] = $store !== null
            ? $this->normalizer->merchantUrlOrNull($store['link'] ?? null)
            : $this->normalizer->merchantUrlOrNull($offer['url'] ?? null);
        $offer['provider_engine'] = SerpApiOfferNormalizer::SEARCH_ENGINE.'+'.self::DETAILS_ENGINE;
        $offer['details_status'] = 'enriched';

        $offer['technical_details'] = array_filter([
            'brand' => $offer['brand'],
            'manufacturer' => $offer['manufacturer'],
            'part_number' => $offer['part_number'],
            'description' => $this->stringOrNull($product['about_the_product']['description'] ?? null),
            'features' => array_values($features),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return $offer;
    }

    /**
     * Rutas reales: product_results.product_attributes[] {name, value} y
     * product_results.about_the_product.features[] {title, value}.
     *
     * @param  array<string, mixed>  $product
     * @return array<int, array{title: string, value: string}>
     */
    private function attributeItems(array $product): array
    {
        $sources = [
            is_array($product['product_attributes'] ?? null) ? $product['product_attributes'] : [],
            is_array($product['about_the_product']['features'] ?? null) ? $product['about_the_product']['features'] : [],
        ];

        $items = [];
        foreach ($sources as $source) {
            foreach ($source as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = $this->stringOrNull($item['title'] ?? $item['name'] ?? null);
                $value = $this->stringOrNull($item['value'] ?? null);
                if ($title !== null && $value !== null) {
                    $items[] = ['title' => $title, 'value' => $value];
                }
            }
        }

        return $items;
    }

    /**
     * Relaciona la oferta seleccionada con product_results.stores[] exigiendo el mismo comercio
     * (stores[].name = shopping_results[].source) y el mismo precio (stores[].extracted_total o
     * stores[].extracted_price = shopping_results[].extracted_price). Nunca se elige por posición
     * ni se toma el enlace de otro comercio o de otra oferta con precio distinto.
     *
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>|null
     */
    private function matchSelectedStore(array $offer, mixed $stores): ?array
    {
        $storeName = $this->normalizer->normalizeLabel($offer['store_name'] ?? null);
        $price = is_numeric($offer['price_numeric'] ?? null) ? (float) $offer['price_numeric'] : null;
        if (! is_array($stores) || $storeName === null || $price === null) {
            return null;
        }

        $matches = [];
        foreach ($stores as $store) {
            if (! is_array($store)
                || $this->normalizer->normalizeLabel($store['name'] ?? null) !== $storeName
                || $this->normalizer->merchantUrlOrNull($store['link'] ?? null) === null
            ) {
                continue;
            }

            $storePrices = array_filter([
                $this->normalizer->numberOrNull($store['extracted_total'] ?? null),
                $this->normalizer->numberOrNull($store['extracted_price'] ?? null),
            ], fn (?float $value): bool => $value !== null);

            foreach ($storePrices as $storePrice) {
                if (abs($storePrice - $price) < 0.005) {
                    $matches[(string) $store['link']] = $store;
                    break;
                }
            }
        }

        return count($matches) === 1 ? reset($matches) : null;
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(4)
            ->timeout(12)
            ->retry(2, 250, function (ConnectionException|RequestException $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response->serverError() || $exception->response->status() === 429));
            });
    }

    /**
     * @param  array<string, mixed>  $parameters  Parámetros de la consulta sin la clave de API.
     */
    private function providerCacheKey(array $parameters): string
    {
        ksort($parameters);

        return 'seibi:serpapi:respuesta:'.hash('sha256', (string) json_encode($parameters));
    }

    private function recordUsage(string $engine, bool $fromCache): void
    {
        $key = $this->usageKey(now()->toDateString(), $engine, $fromCache ? 'cache' : 'provider');
        Cache::add($key, 0, now()->addDays(40));
        Cache::increment($key);
    }

    private function usageKey(string $date, string $engine, string $kind): string
    {
        return 'seibi:serpapi:uso:'.$date.':'.$engine.':'.$kind;
    }

    private function apiKey(): string
    {
        return trim((string) config('services.serpapi.api_key'));
    }

    private function searchCacheKey(int $userId, string $searchId): string
    {
        return 'seibi:part-search:'.$userId.':'.$searchId;
    }

    private function detailsCacheKey(int $userId, string $offerId): string
    {
        return 'seibi:part-details:'.$userId.':'.$offerId;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $this->normalizer->stringOrNull($value);
    }
}
