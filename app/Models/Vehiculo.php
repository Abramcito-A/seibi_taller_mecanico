<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehiculo extends Model
{
    use HasFactory;

    protected $table = 'vehiculos';

    protected $primaryKey = 'id_vehiculo';

    public const CREATED_AT = 'fecha_registro';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_cliente', 'marca', 'modelo', 'anio', 'version', 'placas',
        'numero_serie', 'kilometraje_actual', 'notas',
    ];

    protected function casts(): array
    {
        return ['anio' => 'integer', 'kilometraje_actual' => 'integer'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function ordenesServicio(): HasMany
    {
        return $this->hasMany(OrdenServicio::class, 'id_vehiculo', 'id_vehiculo');
    }
}
