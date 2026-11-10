<?php

namespace App\Services\PartSearch;

class SerpApiOfferNormalizer
{
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
            $numericPrice = $this->normalizePrice($result['extracted_price'] ?? null, $priceString);
            $currency = $this->normalizeCurrency($result['currency'] ?? null, $priceString);
            $url = $this->httpUrlOrNull(
                $result['product_link'] ?? $result['link'] ?? null
            );

            $offers[] = [
                'title' => $title,
                'store_name' => $this->stringOrNull($result['source'] ?? null),
                'price_display' => $priceString,
                'price_numeric' => $numericPrice,
                'currency' => $currency,
                'image_url' => $this->httpUrlOrNull($result['thumbnail'] ?? null),
                'url' => $url,
                'brand' => $this->extractBrand($result),
                'part_number' => $this->extractPartNumber($result),
                'provider_product_id' => $this->stringOrNull(
                    $result['product_id'] ?? ($result['serpapi_product_api'] ?? null)
                ),
                'page_token' => $this->stringOrNull(
                    $result['immersive_product_page_token']
                    ?? null
                ),
                'technical_details' => $this->technicalDetailsFromShoppingResult($result),
            ];
        }

        return $offers;
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

    private function extractBrand(array $result): ?string
    {
        $direct = $this->stringOrNull($result['brand'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        foreach ($result['rich_snippet_extensions'] ?? [] as $extension) {
            if (is_string($extension)) {
                continue;
            }
            if (is_array($extension)) {
                $label = strtolower(trim((string) ($extension['title'] ?? $extension['name'] ?? $extension['label'] ?? '')));
                $value = $extension['value'] ?? $extension['content'] ?? null;
                if (in_array($label, ['brand', 'marca', 'manufacturer', 'fabricante'], true)) {
                    return $this->stringOrNull($value);
                }
            }
        }

        return null;
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

    /**
     * @return array<string, mixed>
     */
    private function technicalDetailsFromShoppingResult(array $result): array
    {
        $details = [];
        $brand = $this->extractBrand($result);
        $partNumber = $this->extractPartNumber($result);
        if ($brand !== null) {
            $details['brand'] = $brand;
        }
        if ($partNumber !== null) {
            $details['part_number'] = $partNumber;
        }

        $features = [];
        foreach ($result['rich_snippet_extensions'] ?? [] as $extension) {
            if (! is_array($extension)) {
                continue;
            }
            $title = $this->stringOrNull($extension['title'] ?? $extension['name'] ?? null);
            $value = $this->stringOrNull($extension['value'] ?? $extension['content'] ?? null);
            if ($title !== null && $value !== null) {
                $features[] = ['title' => $title, 'value' => $value];
            }
        }
        if ($features !== []) {
            $details['features'] = $features;
        }

        return $details;
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
