<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cotizacion extends Model
{
    use HasFactory;

    protected $table = 'cotizaciones';

    protected $primaryKey = 'id_cotizacion';

    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_orden', 'id_usuario', 'id_plantilla', 'folio', 'titulo', 'tipo', 'descripcion',
        'subtotal', 'total', 'estado', 'decision_cliente', 'fecha_envio', 'fecha_decision', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'total' => 'decimal:2',
            'fecha_creacion' => 'datetime', 'fecha_envio' => 'datetime',
            'fecha_decision' => 'datetime', 'fecha_actualizacion' => 'datetime',
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
}
