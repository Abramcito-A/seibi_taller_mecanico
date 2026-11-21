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

    public const ETAPA_REPARACION = 'reparacion';

    public const ETAPA_ENTREGA = 'entrega';

    protected $table = 'evidencias';

    protected $primaryKey = 'id_evidencia';

    public $timestamps = false;

    protected $fillable = [
        'id_orden', 'id_diagnostico', 'id_actualizacion', 'id_usuario', 'tipo_archivo', 'etapa',
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

    public function actualizacion(): BelongsTo
    {
        return $this->belongsTo(ActualizacionReparacion::class, 'id_actualizacion', 'id_actualizacion');
    }

    /**
     * Identificador público de la fotografía: el UUID aleatorio del nombre interno del archivo. Se usa en el portal
     * del cliente para no exponer el id de la evidencia ni la ruta de almacenamiento.
     */
    public function publicReference(): ?string
    {
        $name = pathinfo((string) $this->ruta_archivo, PATHINFO_FILENAME);

        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $name) === 1 ? strtolower($name) : null;
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
