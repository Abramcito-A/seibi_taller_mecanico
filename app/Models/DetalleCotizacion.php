<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DetalleCotizacion extends Model
{
    use HasFactory;

    protected $table = 'detalles_cotizacion';

    protected $primaryKey = 'id_detalle';

    public $timestamps = false;

    /**
     * El token de la oferta es un dato interno del proveedor; no se expone al serializar.
     *
     * @var list<string>
     */
    protected $hidden = ['proveedor_token'];

    public const CONCEPT_TYPES = ['pieza', 'servicio', 'mano_obra', 'otro'];

    /**
     * Clasificación que asigna el mecánico a cada concepto; obligatoria al enviar la cotización al cliente.
     *
     * @var array<string, array{label: string, tone: string, description: string}>
     */
    public const PRIORITIES = [
        'necesario' => ['label' => 'Necesario', 'tone' => 'error', 'description' => 'Requerido para la seguridad o el funcionamiento correcto del vehículo.'],
        'recomendado' => ['label' => 'Recomendado', 'tone' => 'pending', 'description' => 'Conviene realizarlo pronto para evitar fallas o desgaste mayor.'],
        'opcional' => ['label' => 'Opcional', 'tone' => 'info', 'description' => 'Mejora o mantenimiento que puede programarse más adelante.'],
    ];

    public const CLIENT_DECISIONS = ['pendiente', 'aceptado', 'rechazado'];

    /**
     * Campos que el mecánico puede capturar o corregir desde el formulario.
     * El resto (url_shopping, proveedor, proveedor_motor, proveedor_producto_id, detalles_tecnicos)
     * es la instantánea del proveedor y solo la escribe el servidor al agregar una oferta.
     */
    public const EDITABLE_FIELDS = [
        'tipo_concepto', 'prioridad', 'descripcion', 'cantidad', 'precio_unitario', 'tienda_origen',
        'marca', 'fabricante', 'numero_parte', 'url_origen', 'imagen_url',
    ];

    protected $fillable = [
        'id_cotizacion', 'tipo_concepto', 'prioridad', 'descripcion', 'cantidad', 'precio_unitario',
        'importe', 'moneda', 'tienda_origen', 'marca', 'fabricante', 'numero_parte', 'url_origen',
        'url_shopping', 'imagen_url', 'proveedor', 'proveedor_motor', 'proveedor_producto_id', 'detalles_tecnicos',
        'precio_proveedor', 'moneda_original', 'precio_moneda_original', 'titulo_proveedor', 'proveedor_token', 'enlace_estado', 'enlace_motivo', 'enlace_verificado_en',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'precio_unitario' => 'decimal:2',
            'importe' => 'decimal:2',
            'precio_proveedor' => 'decimal:2',
            'precio_moneda_original' => 'decimal:2',
            'detalles_tecnicos' => 'array',
            'enlace_verificado_en' => 'datetime',
            'riesgo_confirmado_en' => 'datetime',
        ];
    }

    /**
     * Conserva únicamente la descripción y las características reales (título/valor) entregadas por el proveedor.
     *
     * @return array{description?: string, features?: array<int, array{title: string, value: string}>}|null
     */
    public static function sanitizeTechnicalDetails(mixed $technicalDetails): ?array
    {
        if (is_string($technicalDetails)) {
            $technicalDetails = json_decode($technicalDetails, true);
        }
        if (! is_array($technicalDetails)) {
            return null;
        }

        $features = [];
        foreach (array_slice(is_array($technicalDetails['features'] ?? null) ? $technicalDetails['features'] : [], 0, 50) as $feature) {
            $title = is_array($feature) && is_string($feature['title'] ?? null) ? trim($feature['title']) : '';
            $value = is_array($feature) && is_string($feature['value'] ?? null) ? trim($feature['value']) : '';
            if ($title !== '' && $value !== '') {
                $features[] = ['title' => mb_substr($title, 0, 150), 'value' => mb_substr($value, 0, 500)];
            }
        }

        $description = is_string($technicalDetails['description'] ?? null) ? trim($technicalDetails['description']) : '';

        $sanitized = array_filter([
            'description' => $description !== '' ? mb_substr($description, 0, 2000) : null,
            'features' => $features,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return $sanitized === [] ? null : $sanitized;
    }

    /**
     * URL directa de la publicación del comercio, solo si es HTTP(S) válida. Origen del botón Comprar.
     */
    public function purchaseUrl(): ?string
    {
        return self::safeExternalUrl($this->url_origen);
    }

    /**
     * URL de la ficha en Google Shopping; nunca se presenta como enlace directo de compra.
     */
    public function shoppingUrl(): ?string
    {
        return self::safeExternalUrl($this->url_shopping);
    }

    /**
     * URL de la imagen del producto; se muestra como imagen y nunca como enlace de compra.
     */
    public function imageUrl(): ?string
    {
        return self::safeExternalUrl($this->imagen_url);
    }

    /**
     * Criterio único de URL externa segura (http/https) usado por el normalizador, la validación y las vistas.
     */
    public static function isSafeExternalUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function safeExternalUrl(?string $url): ?string
    {
        return self::isSafeExternalUrl($url) ? $url : null;
    }

    /**
     * Convierte un decimal positivo con hasta dos decimales a centésimas enteras sin pasar por flotantes.
     */
    public static function decimalToHundredths(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    public static function formatHundredths(int $hundredths): string
    {
        return number_format($hundredths / 100, 2, '.', '');
    }

    /**
     * Importe de una línea en centavos: cantidad × precio con redondeo half-up al centavo.
     */
    public static function lineAmountCents(string $quantity, string $unitPrice): int
    {
        $product = self::decimalToHundredths($quantity) * self::decimalToHundredths($unitPrice);
        $cents = intdiv($product, 100);

        return ($product % 100) >= 50 ? $cents + 1 : $cents;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->prioridad]['label'] ?? 'Sin clasificar';
    }

    public function priorityTone(): string
    {
        return self::PRIORITIES[$this->prioridad]['tone'] ?? 'neutral';
    }

    public function isNecessary(): bool
    {
        return $this->prioridad === 'necesario';
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'id_cotizacion', 'id_cotizacion');
    }

    /**
     * Trabajo de reparación generado cuando el cliente aceptó este concepto (como máximo uno).
     */
    public function trabajoReparacion(): HasOne
    {
        return $this->hasOne(TrabajoReparacion::class, 'id_detalle', 'id_detalle');
    }
}
