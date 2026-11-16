<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenServicio extends Model
{
    use HasFactory;

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
}
