<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->firstName(),
            'apellido' => $this->faker->lastName(),
            'telefono' => $this->faker->numerify('##########'),
            'correo' => $this->faker->optional()->safeEmail(),
            'direccion' => $this->faker->optional()->streetAddress(),
        ];
    }
}
