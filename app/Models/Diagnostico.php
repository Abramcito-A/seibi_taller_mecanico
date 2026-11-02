<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Diagnostico extends Model
{
    use HasFactory;

    protected $table = 'diagnosticos';

    protected $primaryKey = 'id_diagnostico';

    public const CREATED_AT = 'fecha_diagnostico';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['id_orden', 'id_usuario', 'titulo', 'descripcion', 'severidad', 'recomendacion', 'envio_uuid', 'envio_hash'];

    protected function casts(): array
    {
        return ['fecha_diagnostico' => 'datetime'];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'id_orden', 'id_orden');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class, 'id_diagnostico', 'id_diagnostico');
    }
}
