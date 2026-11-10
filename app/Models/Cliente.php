<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente';

    public const CREATED_AT = 'fecha_registro';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['nombre', 'apellido', 'telefono', 'correo', 'direccion'];

    public function vehiculos(): HasMany
    {
        return $this->hasMany(Vehiculo::class, 'id_cliente', 'id_cliente');
    }
}
