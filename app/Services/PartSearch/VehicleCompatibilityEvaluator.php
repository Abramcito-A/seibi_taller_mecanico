<?php

namespace App\Services\PartSearch;

use App\Models\Vehiculo;
use Illuminate\Support\Str;

/**
 * Compara el título de una oferta con el vehículo de la orden para orientar al mecánico.
 *
 * El proveedor actual (Google Shopping vía SerpApi) no entrega datos de aplicación vehicular (fitment),
 * por lo que la compatibilidad nunca se reporta como confirmada: solo se informa si el vendedor menciona
 * el vehículo o si el título contiene evidencia de que la pieza es para otro año, marca, motor o tipo de vehículo.
 */
class VehicleCompatibilityEvaluator
{
    public const MENTIONED = 'mentioned';

    public const CONFLICT = 'conflict';

    public const UNKNOWN = 'unknown';

    /**
     * Armadoras comunes en México (texto normalizado sin acentos).
     */
    public const CAR_MAKERS = [
        'toyota', 'nissan', 'chevrolet', 'chevy', 'general motors', 'gmc', 'ford', 'volkswagen', 'vw', 'honda', 'mazda', 'kia', 'hyundai',
        'dodge', 'jeep', 'chrysler', 'seat', 'renault', 'peugeot', 'mitsubishi', 'suzuki', 'bmw', 'mercedes(-| )?benz', 'mercedes', 'audi',
        'buick', 'cadillac', 'lexus', 'fiat', 'subaru', 'jac', 'baic', 'chirey', 'byd', 'volvo', 'infiniti', 'acura', 'lincoln', 'pontiac',
        'isuzu', 'opel', 'scion', 'citroen', 'porsche', 'tesla', 'land rover', 'jaguar', 'daewoo', 'saturn', 'hummer',
    ];

    /**
     * Grupos de nombres equivalentes de una misma armadora.
     */
    private const MAKER_ALIASES = [
        ['chevrolet', 'chevy', 'gm', 'general motors', 'gmc'],
        ['volkswagen', 'vw'],
        ['mercedes', 'mercedes benz', 'mercedes-benz'],
    ];

    /**
     * Tipos de vehículo o equipo distintos a un automóvil.
     */
    private const OTHER_VEHICLE_TYPES = [
        'montacargas', 'motos?', 'motocicletas?', 'cuatrimotos?', 'scooters?', 'marinos?', 'lanchas?', 'fuera de borda', 'tractor(es)?',
    ];

    /**
     * @return array{status: string, confirmed: false, label: string, evidence: array<int, string>}
     */
    public function evaluate(Vehiculo $vehicle, string $title): array
    {
        $text = $this->normalize($title);
        $make = $this->normalize((string) $vehicle->marca);
        $model = $this->normalize((string) $vehicle->modelo);
        $year = (int) $vehicle->anio;

        $makeAliases = $this->makeAliases($make);
        $mentionsMake = $this->matchesAny($text, $makeAliases);
        $mentionsModel = $model !== '' && preg_match('/(?<![a-z0-9])'.self::modelPattern($model).'(?![a-z0-9])/u', $text) === 1;

        $conflicts = [];

        $otherType = $this->firstMatch($text, self::OTHER_VEHICLE_TYPES);
        if ($otherType !== null) {
            $conflicts[] = 'el título indica uso en "'.$otherType.'"';
        }

        $otherMakers = array_values(array_filter(
            $this->allMatches($text, self::CAR_MAKERS),
            fn (string $maker): bool => ! in_array($maker, $makeAliases, true)
        ));
        if ($otherMakers !== [] && ! $mentionsMake && ! $mentionsModel) {
            $conflicts[] = 'el título menciona otra marca ('.implode(', ', $otherMakers).')';
        }

        $yearRanges = $this->yearRanges($text);
        $yearMatches = null;
        if ($year > 0 && $yearRanges !== []) {
            $yearMatches = false;
            foreach ($yearRanges as [$from, $to]) {
                if ($year >= $from && $year <= $to) {
                    $yearMatches = true;
                    break;
                }
            }
            if (! $yearMatches) {
                $conflicts[] = 'los años del título no incluyen '.$year;
            }
        }

        $vehicleDisplacement = $this->displacements($this->normalize((string) $vehicle->version));
        $titleDisplacement = $this->displacements($text);
        if ($vehicleDisplacement !== [] && $titleDisplacement !== [] && array_intersect($vehicleDisplacement, $titleDisplacement) === []) {
            $conflicts[] = 'el motor indicado ('.implode(', ', $titleDisplacement).' L) no coincide con '.implode(', ', $vehicleDisplacement).' L';
        }

        if ($conflicts !== []) {
            return [
                'status' => self::CONFLICT,
                'confirmed' => false,
                'label' => 'Posible incompatibilidad: '.implode('; ', $conflicts).'.',
                'evidence' => $conflicts,
            ];
        }

        if ($mentionsModel) {
            $evidence = ['el título menciona '.trim($vehicle->marca.' '.$vehicle->modelo)];
            if ($yearMatches === true) {
                $evidence[] = 'el rango de años del título incluye '.$year;
            }

            return [
                'status' => self::MENTIONED,
                'confirmed' => false,
                'label' => 'El vendedor menciona '.trim($vehicle->marca.' '.$vehicle->modelo).($yearMatches === true ? ' '.$year : '').'. No confirmada: verifica número de parte o aplicación.',
                'evidence' => $evidence,
            ];
        }

        return [
            'status' => self::UNKNOWN,
            'confirmed' => false,
            'label' => 'Sin datos de compatibilidad en la publicación. Verifica la aplicación antes de cotizar.',
            'evidence' => [],
        ];
    }

    /**
     * Patrón que tolera guiones o espacios entre las partes del modelo ("CR-V", "CRV", "CR V").
     */
    public static function modelPattern(string $normalizedModel): string
    {
        $parts = preg_split('/[^a-z0-9]+/', $normalizedModel, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('[\s-]?', array_map(fn (string $part): string => preg_quote($part, '/'), $parts));
    }

    /**
     * Rangos de años explícitos: "2020-2022", "2019 a 2023", "2019+", "09-19" y años sueltos.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function yearRanges(string $text): array
    {
        $ranges = [];
        $currentYear = (int) now()->format('Y') + 1;

        if (preg_match_all('/(?<![0-9.])((?:19|20)\d{2})\s*(?:-|a|al|\/)\s*((?:19|20)?\d{2})(?![0-9])/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [$full, $from, $to]) {
                $to = strlen($to) === 2 ? (int) (substr($from, 0, 2).$to) : (int) $to;
                if ($to >= (int) $from) {
                    $ranges[] = [(int) $from, $to];
                }
                $text = str_replace($full, ' ', $text);
            }
        }

        if (preg_match_all('/(?<![0-9.])((?:19|20)\d{2})\s*\+/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [$full, $from]) {
                $ranges[] = [(int) $from, $currentYear];
                $text = str_replace($full, ' ', $text);
            }
        }

        if (preg_match_all('/(?<![a-z0-9.\-])(\d{2})\s*-\s*(\d{2})(?![0-9])/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [$full, $from, $to]) {
                $fromYear = $this->expandTwoDigitYear((int) $from);
                $toYear = $this->expandTwoDigitYear((int) $to);
                if ($fromYear !== null && $toYear !== null && $toYear >= $fromYear) {
                    $ranges[] = [$fromYear, $toYear];
                    $text = str_replace($full, ' ', $text);
                }
            }
        }

        if (preg_match_all('/(?<![0-9.])(19[5-9]\d|20[0-4]\d)(?![0-9])/u', $text, $matches)) {
            foreach ($matches[1] as $single) {
                $ranges[] = [(int) $single, (int) $single];
            }
        }

        return $ranges;
    }

    private function expandTwoDigitYear(int $year): ?int
    {
        $currentShort = (int) now()->format('y') + 1;

        return match (true) {
            $year <= $currentShort => 2000 + $year,
            $year >= 80 => 1900 + $year,
            default => null,
        };
    }

    /**
     * Cilindradas como "1.8", "1.8l", "2.0 lts".
     *
     * @return array<int, string>
     */
    private function displacements(string $text): array
    {
        preg_match_all('/(?<![0-9.])([1-6]\.\d)\s*(?:l|lts?|litros)?(?![0-9])/u', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<int, string>
     */
    private function makeAliases(string $make): array
    {
        foreach (self::MAKER_ALIASES as $group) {
            if (in_array($make, $group, true)) {
                return $group;
            }
        }

        return $make === '' ? [] : [$make];
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function matchesAny(string $text, array $terms): bool
    {
        return $this->firstMatch($text, array_map(fn (string $term): string => preg_quote($term, '/'), $terms)) !== null;
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function firstMatch(string $text, array $terms): ?string
    {
        return $this->allMatches($text, $terms)[0] ?? null;
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function allMatches(string $text, array $terms): array
    {
        if ($text === '' || $terms === []) {
            return [];
        }

        preg_match_all('/(?<![a-z0-9])(?:'.implode('|', $terms).')(?![a-z0-9])/u', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))));
    }
}
