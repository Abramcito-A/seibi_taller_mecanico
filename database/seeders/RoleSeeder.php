<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'nombre' => 'administrador',
                'descripcion' => 'Acceso completo a las funciones administrativas del taller.',
            ],
            [
                'nombre' => 'mecanico',
                'descripcion' => 'Gestión de diagnósticos, órdenes y trabajos mecánicos.',
            ],
            [
                'nombre' => 'recepcionista',
                'descripcion' => 'Recepción de clientes, vehículos y órdenes de servicio.',
            ],
        ];

        foreach ($roles as $datos) {
            Role::updateOrCreate(
                ['nombre' => $datos['nombre']],
                ['descripcion' => $datos['descripcion']]
            );
        }
    }
}
