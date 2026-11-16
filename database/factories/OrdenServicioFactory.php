<?php

namespace Database\Factories;

use App\Models\OrdenServicio;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrdenServicio>
 */
class OrdenServicioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_vehiculo' => Vehiculo::factory(),
            'id_usuario' => User::factory(),
            'folio' => 'OS-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
            'tipo_orden' => 'normal',
            'kilometraje_ingreso' => fake()->numberBetween(0, 300000),
            'descripcion_problema' => fake()->sentence(),
            'observaciones' => fake()->optional()->sentence(),
            'estado' => 'recibida',
        ];
    }
}
