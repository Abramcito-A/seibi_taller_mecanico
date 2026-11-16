<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantillaServicio extends Model
{
    use HasFactory;

    protected $table = 'plantillas_servicio';

    protected $primaryKey = 'id_plantilla';

    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['nombre', 'tipo_servicio', 'descripcion', 'activa', 'id_usuario'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'fecha_creacion' => 'datetime', 'fecha_actualizacion' => 'datetime'];
    }

    public function usuarioCreador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetallePlantilla::class, 'id_plantilla', 'id_plantilla');
    }

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class, 'id_plantilla', 'id_plantilla');
    }
}
