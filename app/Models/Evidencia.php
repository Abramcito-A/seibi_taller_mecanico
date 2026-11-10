<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evidencia extends Model
{
    use HasFactory;

    public const ETAPA_RECEPCION = 'recepcion';

    public const ETAPA_DIAGNOSTICO = 'diagnostico';

    protected $table = 'evidencias';

    protected $primaryKey = 'id_evidencia';

    public $timestamps = false;

    protected $fillable = [
        'id_orden', 'id_diagnostico', 'id_usuario', 'tipo_archivo', 'etapa',
        'nombre_archivo', 'ruta_archivo', 'tipo_mime', 'descripcion', 'visible_cliente',
    ];

    protected function casts(): array
    {
        return [
            'fecha_creacion' => 'datetime',
            'visible_cliente' => 'boolean',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'id_orden', 'id_orden');
    }

    public function diagnostico(): BelongsTo
    {
        return $this->belongsTo(Diagnostico::class, 'id_diagnostico', 'id_diagnostico');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
