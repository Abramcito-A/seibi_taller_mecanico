<?php

namespace App\Services\PartSearch;

use Illuminate\Support\Str;

/**
 * Determina si un resultado comercial pertenece al ámbito automotriz a partir de evidencia explícita
 * en el título, la marca reportada por el proveedor, el comercio y el número de parte buscado.
 *
 * Google Shopping no entrega categoría por resultado, por lo que la clasificación es léxica y explicable:
 * cada señal encontrada se devuelve para que el mecánico vea por qué un resultado se mostró u ocultó.
 * Los diccionarios se construyeron con una muestra real de resultados de SerpApi para México.
 */
class AutomotiveRelevanceClassifier
{
    public const AUTOMOTIVE = 'automotive';

    public const UNCERTAIN = 'uncertain';

    public const EXCLUDED = 'excluded';

    private const AUTOMOTIVE_THRESHOLD = 3;

    /**
     * Piezas, consumibles y productos de mantenimiento inequívocamente automotrices (peso 3).
     */
    private const PART_TERMS = [
        // Frenos
        'balatas?', 'pastillas? (de )?frenos?', 'discos? (de )?frenos?', 'rotor(es)? (de )?frenos?', 'tambor(es)? de frenos?',
        'zapatas? de frenos?', 'calipers?', 'pinzas? de frenos?', 'mordazas? de frenos?', 'frenos?', 'dot ?[345]',
        // Filtros
        'filtros? (de |para )?(aceite|aire|cabina|gasolina|combustible|polen|diesel|transmision)',
        // Encendido
        'bujias?', 'bobinas? (de )?encendido', 'cables? (de |para )?bujias?',
        // Suspensión y dirección
        'amortiguador(es)?', 'struts?', 'rotulas?', 'horquillas?', 'bujes? (de )?(suspension|horquilla)', 'terminal(es)? (de )?direccion',
        'cremalleras? (de )?direccion', 'barras? estabilizadoras?', 'tornillos? estabilizador(es)?', 'bases? (de |para )?amortiguador(es)?', 'suspension',
        // Motor y accesorios
        'bandas? (de )?(distribucion|accesorios|alternador|serpentina|tiempo|motor|direccion)', 'bandas? (micro-?v|multi-?v|en v|dentadas?|automotri(z|ces))',
        'correas? (de )?(distribucion|accesorios|serpentina|tiempo)', 'cadenas? de (tiempo|distribucion)', 'kits? (de )?distribucion',
        'tensor(es)? (de )?(banda|correa|cadena)', 'bombas? (de )?(agua|gasolina|combustible|aceite)', 'radiador(es)?', 'termostatos?',
        'juntas? (de )?(cabeza|culata|tapa)', 'empaques? (de )?(cabeza|punterias|carter|tapa)', 'kits? (de )?afinacion', 'afinacion',
        'anillos de piston', 'arbol de levas', 'ciguenal', 'soportes? (de )?motor', 'multiple de admision', 'cuerpo de aceleracion',
        'inyector(es)?', 'sensor(es)? (de )?(oxigeno|map|maf|tps|ckp|cmp|abs|detonacion)', 'alternador(es)?', 'motor de arranque',
        'embragues?', 'clutch', 'homocineticas?', 'acumulador(es)?', 'baterias? (para auto|automotri(z|ces))', 'mofles?', 'catalizador(es)?',
        'llantas?', 'limpiaparabrisas', 'balero(s)? (de )?rueda', 'mazas? (de )?rueda',
        // Fluidos, aditivos y consumibles
        'aceites? (de |para )?(motor|transmision)', 'aceites? (sinteticos?|semisinteticos?|multigrados?|monogrados?|minerales?)',
        'anticongelantes?', 'antincongelantes?', 'antifreeze', 'kits? (de )?filtros', 'coolant', 'limpiador(es)? (de |para )?(inyectores|inyeccion|carburador|frenos)', 'limpia ?inyectores',
        'aditivos? (de |para )?(gasolina|combustible|motor|aceite|diesel|radiador)', 'octanaje', 'top oil', 'liquidos? (de |para )?(frenos|direccion|transmision|radiador)',
        'atf', 'motor oil', 'discs?,? (front|fr|rear)', 'brake discs?', 'spark plugs?', 'brake pads?', 'shock absorbers?', 'oil filter', 'air filter', 'water pump', 'timing belt', 'serpentine belt',
    ];

    /**
     * Patrones técnicos propios de lubricantes y especificaciones automotrices (peso 3).
     */
    private const TECHNICAL_PATTERNS = [
        '(?:^|[^a-z0-9]|sae)\d{1,2} ?w ?-? ?\d{2}(?![0-9])',
        '(?<![a-z0-9])sae ?\d{2}(?![0-9])',
        '(?<![a-z0-9])api s[a-p](?![a-z0-9])',
        '(?<![a-z0-9])dexos ?\d?(?![a-z0-9])',
    ];

    /**
     * Contexto automotriz que por sí solo no basta (peso 1).
     */
    private const CONTEXT_TERMS = [
        'autos?', 'automotri(z|ces)', 'automovil(es)?', 'carros?', 'coches?', 'vehiculos?', 'vehicular', 'camionetas?', 'motor',
        'refacci(on|ones)', 'autopartes?', 'l4', 'v6', 'v8', '\d\.\d ?(l|lts?|litros)', '\d ?cil(indros)?', 'gasolina', 'diesel', 'aditivos?',
        'refrigerante', 'delanter[oa]s?', 'traser[oa]s?', 'fwd', 'awd', '4x4', 'tensor(es)?', 'poleas?', 'bandas?', 'filtros?', 'aceites?',
        'limpiador(es)?', 'air intake',
    ];

    private const MAX_CONTEXT_POINTS = 2;

    /**
     * Marcas que solo fabrican autopartes, lubricantes o químicos automotrices (peso 3).
     */
    private const AUTOMOTIVE_ONLY_BRANDS = [
        'gonher', 'ngk', 'denso', 'monroe', 'kyb', 'gabriel', 'bilstein', 'boge', 'trw', 'brembo', 'wagner', 'fritec', 'dynamik', 'bruck',
        'moresa', 'acdelco', 'ac delco', 'motorcraft', 'mopar', 'interfil', 'motorfil', 'enginefil', 'sakura', 'fram', 'mahle', 'k&n', 'wix',
        'castrol', 'mobil', 'quaker state', 'pennzoil', 'valvoline', 'bardahl', 'roshfrans', 'akron', 'prestone', 'liqui moly', 'stp', 'gunk',
        'motul', 'grob', 'moog', 'luk', 'cloyes', 'raloy', 'lth', 'contitech', 'rodatech', 'partech', 'canabrake', 'masterfit', 'cardone',
        'dorman', 'centric', 'eibach', 'koni', 'amsoil', 'pentosin', 'garlo', 'roadmatic', 'vafri', 'tbreaker',
    ];

    /**
     * Marcas con líneas automotrices y no automotrices (peso 2): requieren otra señal.
     */
    private const MIXED_BRANDS = [
        'bosch', 'champion', 'gates', 'dayco', 'hella', 'valeo', 'sachs', 'wurth', 'mann', 'syd', 'red line', 'ecom', 'ebc', 'dba', 'peak',
    ];

    /**
     * Comercios especializados reportados como source (peso 2).
     */
    private const AUTOMOTIVE_STORE_PATTERN = '(refaccion|refa100|autopart|autozone|masrefacciones|refaccionaria|auto ?parts|motor)';

    /**
     * Productos claramente ajenos a la mecánica automotriz. Si aparecen, el resultado se descarta aunque
     * comparta palabras con una pieza (p. ej. "bujías para cortadora de césped", "aceite de oliva").
     */
    private const EXCLUSION_TERMS = [
        'audifonos?', 'auriculares?', 'earbuds', 'headphones?', 'airpods', 'celular(es)?', 'smartphones?', 'iphone', 'tablets?', 'laptops?',
        'computadoras?', 'televisi(on|ones)', 'smart ?tv', 'consolas? de videojuegos', 'videojuegos?', 'juguetes?', 'a escala', 'escala 1',
        '1[:/](18|24|32|43|64)', 'die ?-?cast', 'hot wheels', 'maquetas?', 'coleccionables?', 'lego', 'funko', 'peluches?', 'vibrador(es)?',
        'masajeador(es)?', 'sexual(es)?', 'lenceria', 'playeras?', 'camisetas?', 'sudaderas?', 'gorras?', 'vestidos?', 'zapatos?', 'perfumes?',
        'maquillaje', 'cortadoras? de cesped', 'podadoras?', 'motosierras?', 'desbrozadoras?', 'cortasetos', 'minisplit', 'refrigerador(es)?',
        'lavadoras?', 'licuadoras?', 'aspiradoras?', 'purificador(es)?', 'comestibles?', 'vegetal', 'oliva', 'canola', 'girasol', 'aguacate',
        'soya', 'cartamo', 'aceites? de coco', 'extra ?virgen', 'capilar', 'cabello', 'shampoo', 'cosmeticos?', 'corporal(es)?',
        'aceites? esencial(es)?', 'mascotas?', 'cds?', 'lps?', 'albumes', 'musica', 'orquestas?', 'soundtrack', 'jazz', 'bicicletas?',
    ];

    /**
     * Clasifica una oferta normalizada.
     *
     * @param  array<string, mixed>  $offer
     * @return array{status: string, score: int, signals: array<int, string>}
     */
    public function classifyOffer(array $offer, ?string $searchedTerm = null, ?string $vehicleModel = null): array
    {
        $title = $this->normalize($offer['title'] ?? null);
        $brand = $this->normalize($offer['brand'] ?? null);
        $store = $this->normalize($offer['store_name'] ?? null);

        $exclusion = $this->firstMatch($title, self::EXCLUSION_TERMS);
        if ($exclusion !== null) {
            return ['status' => self::EXCLUDED, 'score' => 0, 'signals' => ['Producto ajeno a la mecánica: "'.$exclusion.'"']];
        }

        $score = 0;
        $signals = [];

        $part = $this->firstMatch($title, self::PART_TERMS);
        if ($part !== null) {
            $score += 3;
            $signals[] = 'Pieza o consumible automotriz: "'.$part.'"';
        }

        $technical = $this->firstRegexMatch($title, self::TECHNICAL_PATTERNS);
        if ($technical !== null) {
            $score += 3;
            $signals[] = 'Especificación técnica automotriz: "'.trim($technical).'"';
        }

        $exclusiveBrand = $this->firstMatch($title.' '.$brand, self::AUTOMOTIVE_ONLY_BRANDS);
        $mixedBrand = $exclusiveBrand === null ? $this->firstMatch($title.' '.$brand, self::MIXED_BRANDS) : null;
        if ($exclusiveBrand !== null || $mixedBrand !== null) {
            $score += $exclusiveBrand !== null ? 3 : 2;
            $signals[] = 'Marca de autopartes: "'.($exclusiveBrand ?? $mixedBrand).'"';
        }

        $carMaker = $this->firstMatch($title, VehicleCompatibilityEvaluator::CAR_MAKERS);
        if ($carMaker !== null) {
            $score += 1;
            $signals[] = 'Menciona una armadora: "'.$carMaker.'"';
        }

        $vehicleModel = $this->normalize($vehicleModel);
        if ($vehicleModel !== '' && $this->firstMatch($title, [VehicleCompatibilityEvaluator::modelPattern($vehicleModel)]) !== null) {
            $score += 2;
            $signals[] = 'Menciona el modelo del vehículo de la orden';
        }

        if ($store !== '' && preg_match('/'.self::AUTOMOTIVE_STORE_PATTERN.'/u', $store) === 1) {
            $score += 2;
            $signals[] = 'Comercio especializado: "'.($offer['store_name'] ?? '').'"';
        }

        $partNumber = $this->partNumberCandidate($searchedTerm);
        if ($partNumber !== null && str_contains(str_replace([' ', '-'], '', $title), $partNumber)) {
            $score += 3;
            $signals[] = 'Coincide con el número de parte buscado';
        }

        $contextMatches = $this->allMatches($title, self::CONTEXT_TERMS);
        if ($contextMatches !== []) {
            $score += min(self::MAX_CONTEXT_POINTS, count($contextMatches));
            $signals[] = 'Contexto automotriz: "'.implode('", "', array_slice($contextMatches, 0, 3)).'"';
        }

        return [
            'status' => $score >= self::AUTOMOTIVE_THRESHOLD ? self::AUTOMOTIVE : self::UNCERTAIN,
            'score' => $score,
            'signals' => $signals,
        ];
    }

    /**
     * Evalúa el término escrito por el mecánico antes de consultar al proveedor.
     *
     * - excluded: el término es claramente ajeno; no se consulta al proveedor.
     * - automotive: el término ya es específico (pieza, consumible, especificación o número de parte).
     * - ambiguous: el término puede pertenecer a otros giros ("aceite", "banda") y se acota con un calificador.
     *
     * @return array{status: string, signal: ?string}
     */
    public function assessQuery(string $term): array
    {
        $normalized = $this->normalize($term);

        $exclusion = $this->firstMatch($normalized, self::EXCLUSION_TERMS);
        if ($exclusion !== null) {
            return ['status' => self::EXCLUDED, 'signal' => $exclusion];
        }

        $specific = $this->firstMatch($normalized, self::PART_TERMS)
            ?? $this->firstRegexMatch($normalized, self::TECHNICAL_PATTERNS)
            ?? $this->partNumberCandidate($term);

        if ($specific !== null) {
            return ['status' => self::AUTOMOTIVE, 'signal' => trim($specific)];
        }

        return ['status' => 'ambiguous', 'signal' => null];
    }

    /**
     * Un término de un solo token con letras y dígitos (p. ej. "GP3675M", "BKR5E-11") se trata como número de parte.
     */
    public function partNumberCandidate(?string $term): ?string
    {
        $term = Str::lower(trim((string) $term));
        if ($term === '' || preg_match('/\s/', $term) === 1) {
            return null;
        }

        $compact = str_replace('-', '', $term);

        return strlen($compact) >= 4 && preg_match('/\d/', $compact) === 1 && preg_match('/[a-z]/', $compact) === 1
            && preg_match('/^[a-z0-9]+$/', $compact) === 1
            ? $compact
            : null;
    }

    public function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))));
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function firstMatch(string $text, array $terms): ?string
    {
        if ($text === '') {
            return null;
        }

        $pattern = '/(?<![a-z0-9])(?:'.implode('|', array_map(
            fn (string $term): string => str_replace(['&', '/'], ['\&', '\/'], $term),
            $terms
        )).')(?![a-z0-9])/u';

        return preg_match($pattern, $text, $matches) === 1 ? $matches[0] : null;
    }

    /**
     * Coincidencias distintas de una lista de términos.
     *
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function allMatches(string $text, array $terms): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/(?<![a-z0-9])(?:'.implode('|', $terms).')(?![a-z0-9])/u', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function firstRegexMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match('/'.$pattern.'/u', $text, $matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }
}
