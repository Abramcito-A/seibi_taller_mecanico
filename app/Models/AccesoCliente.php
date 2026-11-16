<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccesoCliente extends Model
{
    use HasFactory;

    protected $table = 'accesos_cliente';

    protected $primaryKey = 'id_acceso';

    public $timestamps = false;

    protected $fillable = ['id_orden', 'token_hash', 'fecha_expiracion', 'revocado', 'ultimo_acceso'];

    protected function casts(): array
    {
        return [
            'revocado' => 'boolean', 'fecha_creacion' => 'datetime',
            'fecha_expiracion' => 'datetime', 'ultimo_acceso' => 'datetime',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'id_orden', 'id_orden');
    }
}
