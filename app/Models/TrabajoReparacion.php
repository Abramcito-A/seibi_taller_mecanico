<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trabajo de reparación derivado de un concepto aceptado por el cliente en una versión exacta de la cotización.
 */
class TrabajoReparacion extends Model
{
    /**
     * Estados individuales del trabajo con su etiqueta, tono de insignia y texto para el cliente.
     *
     * @var array<string, array{label: string, tone: string}>
     */
    public const STATUSES = [
        'pendiente' => ['label' => 'Pendiente', 'tone' => 'neutral'],
        'en_proceso' => ['label' => 'En proceso', 'tone' => 'info'],
        'en_espera' => ['label' => 'En espera', 'tone' => 'pending'],
        'completado' => ['label' => 'Completado', 'tone' => 'success'],
        'no_realizado' => ['label' => 'No realizado', 'tone' => 'error'],
    ];

    /**
     * Transiciones permitidas. Reabrir un trabajo completado o no realizado exige un motivo y queda en la bitácora.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'pendiente' => ['en_proceso', 'en_espera'],
        'en_proceso' => ['en_espera', 'completado'],
        'en_espera' => ['en_proceso', 'no_realizado'],
        'completado' => ['en_proceso'],
        'no_realizado' => ['en_proceso'],
    ];

    /** Estados finales: salir de ellos se registra como reapertura con motivo obligatorio. */
    public const FINAL_STATUSES = ['completado', 'no_realizado'];

    protected $table = 'trabajos_reparacion';

    protected $primaryKey = 'id_trabajo';

    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_orden', 'id_cotizacion', 'id_detalle', 'version_cotizacion', 'tipo_concepto', 'descripcion',
        'cantidad', 'prioridad', 'estado', 'fecha_autorizacion', 'fecha_inicio', 'fecha_finalizacion',
        'id_usuario_responsable',
    ];

    protected $attributes = [
        'estado' => 'pendiente',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'version_cotizacion' => 'integer',
            'fecha_autorizacion' => 'datetime',
            'fecha_inicio' => 'datetime',
            'fecha_finalizacion' => 'datetime',
            'fecha_creacion' => 'datetime',
            'fecha_actualizacion' => 'datetime',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'id_orden', 'id_orden');
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'id_cotizacion', 'id_cotizacion');
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(DetalleCotizacion::class, 'id_detalle', 'id_detalle');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario_responsable');
    }

    public function actualizaciones(): HasMany
    {
        return $this->hasMany(ActualizacionReparacion::class, 'id_trabajo', 'id_trabajo');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->estado]['label'] ?? ucfirst(str_replace('_', ' ', (string) $this->estado));
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->estado]['tone'] ?? 'neutral';
    }

    public function priorityLabel(): string
    {
        return DetalleCotizacion::PRIORITIES[$this->prioridad]['label'] ?? 'Sin clasificar';
    }

    public function priorityTone(): string
    {
        return DetalleCotizacion::PRIORITIES[$this->prioridad]['tone'] ?? 'neutral';
    }

    /**
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->estado] ?? [];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this->estado, self::FINAL_STATUSES, true);
    }

    public function quantityLabel(): string
    {
        return $this->tipo_concepto === 'mano_obra'
            ? number_format((float) $this->cantidad, 2, '.', ',').' h'
            : rtrim(rtrim(number_format((float) $this->cantidad, 2, '.', ','), '0'), '.');
    }
}
