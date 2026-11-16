<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Vehiculo;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehiculoFactory extends Factory
{
    protected $model = Vehiculo::class;

    public function definition(): array
    {
        $marcas = ['Toyota', 'Nissan', 'Chevrolet', 'Ford', 'Honda', 'Volkswagen', 'Mazda', 'Kia'];
        $marca = $this->faker->randomElement($marcas);

        return [
            'id_cliente' => Cliente::factory(),
            'marca' => $marca,
            'modelo' => $this->faker->word(),
            'anio' => $this->faker->numberBetween(2000, (int) date('Y')),
            'version' => $this->faker->optional()->randomElement(['LX', 'EX', 'SE', 'Sport', 'Base']),
            'placas' => $this->faker->optional()->bothify('???-####'),
            'numero_serie' => $this->faker->optional()->bothify('1HGBH41JXMN######'),
            'kilometraje_actual' => $this->faker->optional()->numberBetween(0, 300000),
            'notas' => $this->faker->optional()->sentence(),
        ];
    }
}
