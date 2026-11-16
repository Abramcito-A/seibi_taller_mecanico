<?php

namespace App\Services\PartSearch;

use Illuminate\Support\Str;

/**
 * Normaliza los resultados de SerpApi (engine google_shopping) a la estructura interna de ofertas.
 *
 * Rutas reales verificadas en shopping_results[]: title, source, price, extracted_price, thumbnail,
 * product_link (página de Google Shopping), product_id, immersive_product_page_token,
 * serpapi_product_api y serpapi_immersive_product_api. Google Shopping no entrega la URL
 * directa del comercio, marca ni número de parte; esos datos se obtienen con google_product.
 */
class SerpApiOfferNormalizer
{
    public const PROVIDER = 'serpapi';

    public const SEARCH_ENGINE = 'google_shopping';

    /**
     * @param  array<int, mixed>  $shoppingResults
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $shoppingResults): array
    {
        $offers = [];

        foreach ($shoppingResults as $result) {
            if (! is_array($result)) {
                continue;
            }

            $title = $this->stringOrNull($result['title'] ?? null);
            if ($title === null) {
                continue;
            }

            $priceString = $this->stringOrNull($result['price'] ?? null);
            [$originalCurrency, $originalPrice] = $this->originalCurrencyPrice($result['alternative_price'] ?? null);
            $brand = $this->stringOrNull($result['brand'] ?? null);
            $partNumber = $this->extractPartNumber($result);

            $offers[] = [
                'provider' => self::PROVIDER,
                'provider_engine' => self::SEARCH_ENGINE,
                'provider_product_id' => $this->stringOrNull($result['product_id'] ?? null),
                'page_token' => $this->stringOrNull($result['immersive_product_page_token'] ?? null),
                'title' => $title,
                'store_name' => $this->stringOrNull($result['source'] ?? null),
                'brand' => $brand,
                'manufacturer' => null,
                'part_number' => $partNumber,
                'price_display' => $priceString,
                'price_numeric' => $this->normalizePrice($result['extracted_price'] ?? null, $priceString),
                'currency' => $this->normalizeCurrency($result['currency'] ?? null, $priceString),
                'original_currency' => $originalCurrency,
                'original_price' => $originalPrice,
                'image_url' => $this->httpUrlOrNull($result['thumbnail'] ?? null),
                'shopping_url' => $this->googleShoppingUrlOrNull($result['product_link'] ?? null),
                'url' => $this->merchantUrlOrNull($result['link'] ?? null),
                'technical_details' => array_filter([
                    'brand' => $brand,
                    'part_number' => $partNumber,
                ], fn (?string $value): bool => $value !== null),
            ];
        }

        return $offers;
    }

    /**
     * Devuelve la URL solo si es HTTP(S) y no pertenece a Google, SerpApi o a un CDN de imágenes.
     */
    public function merchantUrlOrNull(mixed $value): ?string
    {
        $url = $this->httpUrlOrNull($value);
        if ($url === null) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $this->isGoogleHost($host) || $this->hostMatches($host, ['gstatic.com', 'googleusercontent.com', 'serpapi.com'])
            ? null
            : $url;
    }

    public function googleShoppingUrlOrNull(mixed $value): ?string
    {
        $url = $this->httpUrlOrNull($value);

        return $url !== null && $this->isGoogleHost(strtolower((string) parse_url($url, PHP_URL_HOST)))
            ? $url
            : null;
    }

    /**
     * Google entrega product_link con espacios literales en el parámetro q; se codifican antes de validar.
     */
    public function httpUrlOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        $value = $value !== null ? str_replace(' ', '%20', $value) : null;

        return $value !== null && filter_var($value, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $value
            : null;
    }

    public function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Normaliza nombres de comercio y etiquetas para compararlos sin acentos, mayúsculas ni espacios extra.
     */
    public function normalizeLabel(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) {
            return null;
        }

        return (string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value)));
    }

    public function numberOrNull(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private function isGoogleHost(string $host): bool
    {
        return preg_match('/(^|\.)google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host) === 1;
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function hostMatches(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePrice(mixed $extractedPrice, ?string $priceString): ?float
    {
        if (is_int($extractedPrice) || is_float($extractedPrice)) {
            return max(0, round((float) $extractedPrice, 2));
        }

        if (is_string($extractedPrice) && preg_match('/^\d+(?:\.\d{1,2})?$/', trim($extractedPrice))) {
            return max(0, round((float) $extractedPrice, 2));
        }

        if ($priceString === null) {
            return null;
        }

        if (preg_match('/(\d[\d,]*(?:\.\d{1,2})?)/u', $priceString, $matches) === 1) {
            $value = str_replace(',', '', $matches[1]);

            return max(0, round((float) $value, 2));
        }

        return null;
    }

    private function normalizeCurrency(mixed $currency, ?string $priceString): ?string
    {
        if (is_string($currency) && strtoupper(trim($currency)) === 'MXN') {
            return 'MXN';
        }

        if ($priceString !== null && preg_match('/\bMXN\b|\bMX\$/iu', $priceString) === 1) {
            return 'MXN';
        }

        return null;
    }

    /**
     * alternative_price {extracted_price, currency} indica que el comercio publica en otra moneda y Google muestra
     * el precio convertido a MXN. Es la evidencia que permite tolerar variaciones por tipo de cambio.
     *
     * @return array{0: ?string, 1: ?float}
     */
    public function originalCurrencyPrice(mixed $alternativePrice): array
    {
        if (! is_array($alternativePrice)) {
            return [null, null];
        }

        $currency = is_string($alternativePrice['currency'] ?? null) ? strtoupper(trim($alternativePrice['currency'])) : '';
        $amount = $this->numberOrNull($alternativePrice['extracted_price'] ?? null);

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 && $currency !== 'MXN' && $amount !== null && $amount > 0
            ? [$currency, round($amount, 2)]
            : [null, null];
    }

    private function extractPartNumber(array $result): ?string
    {
        return $this->stringOrNull(
            $result['part_number']
            ?? $result['mpn']
            ?? $result['manufacturer_part_number']
            ?? null
        );
    }
}
