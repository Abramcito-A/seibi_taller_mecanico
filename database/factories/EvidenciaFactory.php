<?php

namespace Database\Factories;

use App\Models\Evidencia;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Evidencia>
 */
class EvidenciaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_orden' => OrdenServicio::factory(),
            'id_usuario' => User::factory(),
            'tipo_archivo' => 'imagen',
            'etapa' => Evidencia::ETAPA_RECEPCION,
            'nombre_archivo' => 'fotografia.jpg',
            'ruta_archivo' => 'ordenes/'.Str::uuid().'/recepcion/'.Str::uuid().'.jpg',
            'tipo_mime' => 'image/jpeg',
            'descripcion' => 'Fotografía inicial de recepción.',
            'visible_cliente' => false,
        ];
    }
}
