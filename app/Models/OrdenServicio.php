<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenServicio extends Model
{
    use HasFactory;

    /**
     * Etiquetas de los estados operativos de la orden.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        'recibida' => 'Recibida',
        'en_diagnostico' => 'En diagnóstico',
        'esperando_aprobacion' => 'Esperando aprobación',
        'esperando_refaccion' => 'Esperando refacciones',
        'esperando_refacciones' => 'Esperando refacciones',
        'en_reparacion' => 'En reparación',
        'lista_entrega' => 'Lista para entrega',
        'entregada' => 'Entregada',
        'cerrada' => 'Cerrada',
        'cancelada' => 'Cancelada',
    ];

    /** Estados en los que la orden terminó; el enlace del cliente queda en consulta por un plazo limitado. */
    public const CLOSED_STATUSES = ['entregada', 'cerrada', 'cancelada'];

    /** Estados en los que el mecánico registra avances de reparación. */
    public const REPAIR_STATUSES = ['en_reparacion', 'esperando_refaccion', 'esperando_refacciones'];

    protected $table = 'ordenes_servicio';

    protected $primaryKey = 'id_orden';

    public const CREATED_AT = 'fecha_ingreso';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_vehiculo', 'kilometraje_ingreso', 'descripcion_problema', 'observaciones',
    ];

    protected $attributes = [
        'estado' => 'recibida',
    ];

    protected function casts(): array
    {
        return ['fecha_ingreso' => 'datetime', 'fecha_cierre' => 'datetime', 'kilometraje_ingreso' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (OrdenServicio $orden): void {
            if ($orden->estado === 'cerrada' && $orden->fecha_cierre === null) {
                $orden->fecha_cierre = now();
            }
        });
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'id_vehiculo', 'id_vehiculo');
    }

    public function usuarioResponsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function ordenOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_orden_origen', 'id_orden');
    }

    public function ordenesGarantia(): HasMany
    {
        return $this->hasMany(self::class, 'id_orden_origen', 'id_orden');
    }

    public function diagnosticos(): HasMany
    {
        return $this->hasMany(Diagnostico::class, 'id_orden', 'id_orden');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class, 'id_orden', 'id_orden');
    }

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class, 'id_orden', 'id_orden');
    }

    public function historialEstados(): HasMany
    {
        return $this->hasMany(HistorialEstado::class, 'id_orden', 'id_orden');
    }

    public function accesosCliente(): HasMany
    {
        return $this->hasMany(AccesoCliente::class, 'id_orden', 'id_orden');
    }

    public function trabajosReparacion(): HasMany
    {
        return $this->hasMany(TrabajoReparacion::class, 'id_orden', 'id_orden');
    }

    public function actualizacionesReparacion(): HasMany
    {
        return $this->hasMany(ActualizacionReparacion::class, 'id_orden', 'id_orden');
    }

    public function isClosed(): bool
    {
        return in_array($this->estado, self::CLOSED_STATUSES, true);
    }

    public function isInRepair(): bool
    {
        return in_array($this->estado, self::REPAIR_STATUSES, true);
    }
}
