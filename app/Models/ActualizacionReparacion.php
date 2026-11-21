<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registro de la bitácora de reparación. No se edita ni se elimina: las correcciones se agregan como un registro nuevo
 * que referencia al anterior, y la única modificación posible es publicar al cliente un registro interno.
 */
class ActualizacionReparacion extends Model
{
    /** @var array<string, string> */
    public const TYPES = [
        'avance' => 'Avance',
        'correccion' => 'Corrección',
        'revision_final' => 'Revisión final',
    ];

    protected $table = 'actualizaciones_reparacion';

    protected $primaryKey = 'id_actualizacion';

    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id_orden', 'id_trabajo', 'id_usuario', 'tipo', 'id_actualizacion_corregida', 'titulo', 'descripcion',
        'motivo', 'estado_anterior', 'estado_nuevo', 'visible_cliente', 'fecha_publicacion', 'id_usuario_publicacion',
    ];

    protected function casts(): array
    {
        return [
            'visible_cliente' => 'boolean',
            'fecha_publicacion' => 'datetime',
            'fecha_creacion' => 'datetime',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'id_orden', 'id_orden');
    }

    public function trabajo(): BelongsTo
    {
        return $this->belongsTo(TrabajoReparacion::class, 'id_trabajo', 'id_trabajo');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function usuarioPublicacion(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario_publicacion');
    }

    public function corregida(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_actualizacion_corregida', 'id_actualizacion');
    }

    public function correcciones(): HasMany
    {
        return $this->hasMany(self::class, 'id_actualizacion_corregida', 'id_actualizacion');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class, 'id_actualizacion', 'id_actualizacion');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->tipo] ?? ucfirst((string) $this->tipo);
    }

    public function isPublished(): bool
    {
        return $this->visible_cliente && $this->fecha_publicacion !== null;
    }

    public function changesStatus(): bool
    {
        return $this->estado_nuevo !== null && $this->estado_nuevo !== $this->estado_anterior;
    }
}
