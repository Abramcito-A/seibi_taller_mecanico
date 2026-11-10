<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleCotizacion extends Model
{
    use HasFactory;

    protected $table = 'detalles_cotizacion';

    protected $primaryKey = 'id_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_cotizacion', 'tipo_concepto', 'descripcion', 'cantidad', 'precio_unitario',
        'importe', 'moneda', 'tienda_origen', 'marca', 'numero_parte', 'url_origen', 'imagen_url',
    ];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2', 'precio_unitario' => 'decimal:2', 'importe' => 'decimal:2'];
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'id_cotizacion', 'id_cotizacion');
    }
}
