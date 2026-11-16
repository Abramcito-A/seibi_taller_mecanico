<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetallePlantilla extends Model
{
    use HasFactory;

    protected $table = 'detalles_plantilla';

    protected $primaryKey = 'id_detalle_plantilla';

    public $timestamps = false;

    protected $fillable = ['id_plantilla', 'tipo_concepto', 'descripcion', 'cantidad', 'precio_unitario_sugerido'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2', 'precio_unitario_sugerido' => 'decimal:2'];
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaServicio::class, 'id_plantilla', 'id_plantilla');
    }
}
