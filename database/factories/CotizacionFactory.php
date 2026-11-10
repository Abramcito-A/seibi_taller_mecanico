<?php

namespace Database\Factories;

use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cotizacion>
 */
class CotizacionFactory extends Factory
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
            'folio' => 'COT-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'titulo' => fake()->sentence(3),
            'tipo' => fake()->randomElement(['diagnostico', 'mantenimiento', 'reparacion', 'otro']),
            'descripcion' => fake()->optional()->sentence(),
            'subtotal' => '0.00',
            'total' => '0.00',
            'estado' => 'borrador',
            'decision_cliente' => 'pendiente',
            'observaciones' => fake()->optional()->sentence(),
        ];
    }
}
