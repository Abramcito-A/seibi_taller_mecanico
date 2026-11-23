<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Búsqueda de clientes. Si el término es un número telefónico (solo dígitos, espacios, guiones, paréntesis,
     * puntos o +) se busca únicamente por teléfono, ignorando el formato con el que se guardó (871 576 6792,
     * +52 871-576-6792, etc.). En otro caso busca por nombre, apellido, teléfono o correo.
     */
    public function scopeBuscar(Builder $query, string $search): Builder
    {
        $normalizedSearch = preg_replace('/\s+/', ' ', trim($search)) ?? trim($search);
        if ($normalizedSearch === '') {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', $normalizedSearch) ?? '';
        if ($digits !== '' && preg_match('/^[\d\s\-().+]+$/', $normalizedSearch) === 1) {
            return $query->whereRaw("REGEXP_REPLACE(telefono, '[^0-9]', '') LIKE ?", ['%'.$digits.'%']);
        }

        $pattern = '%'.$normalizedSearch.'%';
        $terms = preg_split('/\s+/', $normalizedSearch, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $query->where(function (Builder $builder) use ($pattern, $terms): void {
            $builder->where(function (Builder $fullNameQuery) use ($pattern): void {
                $fullNameQuery->where('nombre', 'like', $pattern)
                    ->orWhere('apellido', 'like', $pattern)
                    ->orWhere('telefono', 'like', $pattern)
                    ->orWhere('correo', 'like', $pattern)
                    ->orWhereRaw("CONCAT(nombre, ' ', apellido) LIKE ?", [$pattern])
                    ->orWhereRaw("CONCAT(apellido, ' ', nombre) LIKE ?", [$pattern]);
            });

            foreach ($terms as $term) {
                $termPattern = '%'.$term.'%';
                $builder->where(function (Builder $termQuery) use ($termPattern): void {
                    $termQuery->where('nombre', 'like', $termPattern)
                        ->orWhere('apellido', 'like', $termPattern)
                        ->orWhere('telefono', 'like', $termPattern)
                        ->orWhere('correo', 'like', $termPattern);
                });
            }
        });
    }
}
