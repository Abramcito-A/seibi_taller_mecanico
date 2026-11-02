<?php

use App\Models\Cliente;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('each staff role reaches its own dashboard after login', function (string $roleName, string $dashboardTitle) {
    $user = userWithRole($roleName);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee($dashboardTitle)
        ->assertSee($roleName === 'mecanico' ? 'Mecánico' : ucfirst($roleName));
})->with([
    'administrator dashboard' => ['administrador', 'Resumen del taller'],
    'mechanic dashboard' => ['mecanico', 'Trabajo técnico'],
    'receptionist dashboard' => ['recepcionista', 'Recepción y atención'],
]);

test('sidebar exposes the single service orders module with role-specific actions', function (string $roleName, bool $canCreateReception) {
    $this->actingAs(userWithRole($roleName));

    $response = $this->get(route('dashboard'))->assertOk();
    $response->assertSee('Órdenes de servicio')
        ->assertSee('Todas las órdenes')
        ->assertSee(route('ordenes.index'), false)
        ->assertSee(route('clientes.index'), false)
        ->assertSee(route('vehiculos.index'), false);

    if ($canCreateReception) {
        $response->assertSee('Nueva recepción')
            ->assertSee(route('ordenes.create'), false);
    } else {
        $response->assertDontSee('Nueva recepción')
            ->assertDontSee(route('ordenes.create'), false);
    }

    expect(substr_count($response->getContent(), 'Órdenes de servicio'))->toBeGreaterThanOrEqual(1);
})->with([
    'administrator navigation' => ['administrador', true],
    'mechanic navigation' => ['mecanico', false],
    'receptionist navigation' => ['recepcionista', true],
]);

test('administrators can use every existing customer and vehicle action', function () {
    $this->actingAs(userWithRole('administrador'));
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create();

    $this->get(route('clientes.create'))->assertOk();
    $this->get(route('clientes.edit', $cliente))->assertOk();
    $this->get(route('vehiculos.create'))->assertOk();
    $this->get(route('vehiculos.edit', $vehiculo))->assertOk();
    $this->delete(route('clientes.destroy', $cliente))->assertRedirect(route('clientes.index'));
    $this->delete(route('vehiculos.destroy', $vehiculo))->assertRedirect(route('vehiculos.index'));
});

test('mechanics can consult customers and vehicles but cannot modify them directly', function () {
    $this->actingAs(userWithRole('mecanico'));
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create();

    $this->get(route('clientes.index'))->assertOk();
    $this->get(route('clientes.show', $cliente))->assertOk();
    $this->get(route('vehiculos.index'))->assertOk();
    $this->get(route('vehiculos.show', $vehiculo))->assertOk();

    $forbidden = $this->get(route('clientes.create'));
    $forbidden->assertForbidden()
        ->assertSee('Acceso no autorizado')
        ->assertSee('Esta sección está fuera de los permisos de tu perfil.')
        ->assertSee('Regresar al panel')
        ->assertSee('Volver atrás')
        ->assertSee(route('dashboard'), false);
    $this->put(route('clientes.update', $cliente), ['nombre' => 'No autorizado'])->assertForbidden();
    $this->delete(route('clientes.destroy', $cliente))->assertForbidden();
    $this->get(route('vehiculos.create'))->assertForbidden();
    $this->put(route('vehiculos.update', $vehiculo), ['marca' => 'No autorizada'])->assertForbidden();
    $this->delete(route('vehiculos.destroy', $vehiculo))->assertForbidden();

    $this->get(route('clientes.index'))
        ->assertDontSee('Nuevo cliente')
        ->assertDontSee('Editar')
        ->assertDontSee('Eliminar');
});

test('visitors remain redirected to login instead of receiving a forbidden page', function () {
    $this->get(route('clientes.create'))->assertRedirect(route('login'));
});

test('not found and server errors do not use the forbidden page', function () {
    Route::get('/__tests/error-500', fn () => abort(500))
        ->middleware(['auth', 'active', 'role:administrador']);

    $this->actingAs(userWithRole('administrador'));

    $this->get('/__tests/not-found')
        ->assertNotFound()
        ->assertDontSee('Acceso no autorizado');

    $this->get('/__tests/error-500')
        ->assertStatus(500)
        ->assertDontSee('Acceso no autorizado');
});

test('receptionists can create and update customers and vehicles but cannot delete them', function () {
    $this->actingAs(userWithRole('recepcionista'));
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create();

    $this->get(route('clientes.create'))->assertOk();
    $this->get(route('clientes.edit', $cliente))->assertOk();
    $this->get(route('vehiculos.create'))->assertOk();
    $this->get(route('vehiculos.edit', $vehiculo))->assertOk();

    $this->post(route('clientes.store'), [
        'nombre' => 'Cliente recepción',
        'apellido' => 'Prueba',
        'telefono' => '5512345678',
    ])->assertRedirect();

    $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente recepción']);

    $this->post(route('vehiculos.store'), [
        'id_cliente' => $cliente->id_cliente,
        'marca' => 'Recepción',
        'modelo' => 'Registro',
        'anio' => 2024,
    ])->assertRedirect();

    $this->assertDatabaseHas('vehiculos', ['marca' => 'Recepción', 'modelo' => 'Registro']);

    $this->put(route('clientes.update', $cliente), [
        'nombre' => 'Recepción',
        'apellido' => $cliente->apellido,
        'telefono' => $cliente->telefono,
    ])->assertRedirect(route('clientes.show', $cliente));

    $this->put(route('vehiculos.update', $vehiculo), [
        'id_cliente' => $vehiculo->id_cliente,
        'marca' => 'Actualizado',
        'modelo' => $vehiculo->modelo,
        'anio' => $vehiculo->anio,
    ])->assertRedirect(route('vehiculos.show', $vehiculo));

    $this->delete(route('clientes.destroy', $cliente))->assertForbidden();
    $this->delete(route('vehiculos.destroy', $vehiculo))->assertForbidden();

    $this->get(route('clientes.index'))
        ->assertSee('Nuevo cliente')
        ->assertSee('Editar')
        ->assertDontSee('Eliminar');
});

test('unimplemented user and technical modules have no public route or navigation link', function () {
    $this->actingAs(userWithRole('mecanico'));

    foreach (['/usuarios', '/roles', '/diagnosticos', '/cotizaciones', '/autopartes'] as $path) {
        $this->get($path)->assertNotFound();
    }

    $this->get(route('dashboard'))
        ->assertSee(route('ordenes.index'), false)
        ->assertDontSee('/usuarios')
        ->assertDontSee('/diagnosticos')
        ->assertDontSee('/cotizaciones')
        ->assertDontSee('/autopartes');
});

function userWithRole(string $roleName): User
{
    $role = Role::query()->firstOrCreate(
        ['nombre' => $roleName],
        ['descripcion' => 'Rol para pruebas automatizadas.'],
    );

    return User::factory()->create(['id_rol' => $role->id_rol]);
}
