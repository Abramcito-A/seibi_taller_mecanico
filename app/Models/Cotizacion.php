<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Cotizacion extends Model
{
    use HasFactory;

    /**
     * Etiqueta y tono de badge de cada estado de la cotización (independiente del estado de la orden).
     *
     * @var array<string, array{label: string, tone: string}>
     */
    public const STATUSES = [
        'borrador' => ['label' => 'Borrador', 'tone' => 'pending'],
        'enviada' => ['label' => 'Enviada · pendiente de respuesta', 'tone' => 'info'],
        'aprobada' => ['label' => 'Autorizada', 'tone' => 'success'],
        'parcialmente_aprobada' => ['label' => 'Autorizada parcialmente', 'tone' => 'pending'],
        'rechazada' => ['label' => 'Rechazada', 'tone' => 'error'],
        'vencida' => ['label' => 'Vencida', 'tone' => 'neutral'],
        'cancelada' => ['label' => 'Cancelada', 'tone' => 'error'],
        'sustituida' => ['label' => 'Sustituida por otra versión', 'tone' => 'neutral'],
    ];

    /** Estados con una decisión registrada por el cliente. */
    public const DECIDED_STATUSES = ['aprobada', 'parcialmente_aprobada', 'rechazada'];

    /** Estados con al menos un concepto aceptado: generan trabajos y el enlace pasa a modo de seguimiento. */
    public const AUTHORIZED_STATUSES = ['aprobada', 'parcialmente_aprobada'];

    protected $table = 'cotizaciones';

    protected $primaryKey = 'id_cotizacion';

    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_orden', 'id_usuario', 'id_plantilla', 'folio', 'titulo', 'tipo', 'descripcion',
        'subtotal', 'total', 'estado', 'revision', 'version', 'id_cotizacion_origen',
        'decision_cliente', 'fecha_envio', 'fecha_decision', 'observaciones',
    ];

    /**
     * La huella de contenido es un dato de control interno; no se expone al serializar.
     *
     * @var list<string>
     */
    protected $hidden = ['huella_contenido'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'importe_autorizado' => 'decimal:2',
            'revision' => 'integer', 'version' => 'integer',
            'fecha_creacion' => 'datetime', 'fecha_envio' => 'datetime',
            'fecha_decision' => 'datetime', 'fecha_actualizacion' => 'datetime',
            'fecha_vencimiento' => 'datetime',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'id_orden', 'id_orden');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaServicio::class, 'id_plantilla', 'id_plantilla');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleCotizacion::class, 'id_cotizacion', 'id_cotizacion');
    }

    /**
     * Cotización de la que esta versión fue derivada (null en la versión original).
     */
    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_cotizacion_origen', 'id_cotizacion');
    }

    /**
     * Versión creada a partir de esta cotización (como máximo una por cotización).
     */
    public function siguienteVersion(): HasOne
    {
        return $this->hasOne(self::class, 'id_cotizacion_origen', 'id_cotizacion');
    }

    public function accesos(): HasMany
    {
        return $this->hasMany(AccesoCliente::class, 'id_cotizacion', 'id_cotizacion');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoCotizacion::class, 'id_cotizacion', 'id_cotizacion');
    }

    public function trabajosReparacion(): HasMany
    {
        return $this->hasMany(TrabajoReparacion::class, 'id_cotizacion', 'id_cotizacion');
    }

    /**
     * Autorización total o parcial vigente (una versión sustituida conserva su decisión solo como historial).
     */
    public function isAuthorized(): bool
    {
        return in_array($this->estado, self::AUTHORIZED_STATUSES, true);
    }

    public function isEditableDraft(): bool
    {
        return $this->estado === 'borrador';
    }

    /**
     * Recalcula subtotal y total a partir de los importes persistidos de los conceptos, en centavos enteros.
     * No guarda el modelo; quien llama decide cuándo persistir dentro de su transacción.
     */
    public function recalculateTotals(): void
    {
        $subtotalCents = $this->detalles()
            ->get(['importe'])
            ->sum(fn (DetalleCotizacion $detail): int => DetalleCotizacion::decimalToHundredths((string) $detail->importe));

        $this->subtotal = DetalleCotizacion::formatHundredths($subtotalCents);
        $this->total = DetalleCotizacion::formatHundredths($subtotalCents);
    }

    public function isAwaitingDecision(): bool
    {
        return $this->estado === 'enviada' && $this->decision_cliente === 'pendiente';
    }

    public function hasClientDecision(): bool
    {
        return in_array($this->estado, self::DECIDED_STATUSES, true)
            || in_array($this->decision_cliente, ['aprobada', 'parcial', 'rechazada'], true);
    }

    public function isPastDueDate(): bool
    {
        return $this->fecha_vencimiento !== null && $this->fecha_vencimiento->isPast();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->estado]['label'] ?? ucfirst(str_replace('_', ' ', (string) $this->estado));
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->estado]['tone'] ?? 'neutral';
    }

    /**
     * Huella SHA-256 determinista del contenido comercial que ve el cliente: datos generales, totales, versión
     * y cada concepto ordenado por id_detalle. Excluye decisiones, estados, fechas y datos internos del proveedor.
     * Los importes se representan en centésimas enteras para no depender del formato decimal ni de flotantes.
     */
    public function contentFingerprint(): string
    {
        $details = $this->detalles()->orderBy('id_detalle')->get()->map(fn (DetalleCotizacion $detail): array => [
            'id_detalle' => (int) $detail->id_detalle,
            'tipo_concepto' => (string) $detail->tipo_concepto,
            'prioridad' => $detail->prioridad,
            'descripcion' => (string) $detail->descripcion,
            'cantidad' => DetalleCotizacion::decimalToHundredths((string) $detail->cantidad),
            'precio_unitario' => DetalleCotizacion::decimalToHundredths((string) $detail->precio_unitario),
            'importe' => DetalleCotizacion::decimalToHundredths((string) $detail->importe),
            'moneda' => (string) $detail->moneda,
            'marca' => $detail->marca,
            'numero_parte' => $detail->numero_parte,
            'imagen_url' => $detail->imagen_url,
        ])->values()->all();

        $payload = [
            'id_cotizacion' => (int) $this->id_cotizacion,
            'id_orden' => (int) $this->id_orden,
            'folio' => (string) $this->folio,
            'version' => (int) $this->version,
            'titulo' => (string) $this->titulo,
            'tipo' => (string) $this->tipo,
            'descripcion' => $this->descripcion,
            'subtotal' => DetalleCotizacion::decimalToHundredths((string) $this->subtotal),
            'total' => DetalleCotizacion::decimalToHundredths((string) $this->total),
            'detalles' => $details,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function hasValidFingerprint(): bool
    {
        return is_string($this->huella_contenido)
            && strlen($this->huella_contenido) === 64
            && hash_equals($this->huella_contenido, $this->contentFingerprint());
    }

    /**
     * Fecha en la zona horaria de presentación de SEIBI (las fechas se guardan en la zona de la aplicación).
     */
    public static function formatForDisplay(?\DateTimeInterface $date, string $format = 'd/m/Y H:i'): string
    {
        if ($date === null) {
            return '—';
        }

        return Carbon::instance($date)->copy()->setTimezone(config('seibi.display_timezone'))->locale('es')->translatedFormat($format);
    }
}
