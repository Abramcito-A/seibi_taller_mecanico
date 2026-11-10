<?php

use App\Models\Cliente;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::query()->firstOrCreate(
        ['nombre' => 'administrador'],
        ['descripcion' => 'Rol para pruebas automatizadas.'],
    );

    $this->actingAs(User::factory()->create(['id_rol' => $role->id_rol]));
});

// ─── INDEX ────────────────────────────────────────────────────────────────────

test('lista de clientes devuelve 200', function () {
    $response = $this->get(route('clientes.index'));
    $response->assertStatus(200);
});

test('lista de clientes muestra registros existentes', function () {
    $cliente = Cliente::factory()->create(['nombre' => 'TestNombre', 'apellido' => 'TestApellido']);

    $response = $this->get(route('clientes.index'));

    $response->assertStatus(200);
    $response->assertSee('TestNombre');
});

test('búsqueda de clientes filtra por nombre', function () {
    Cliente::factory()->create(['nombre' => 'UnicoNombre', 'apellido' => 'Apellido']);
    Cliente::factory()->create(['nombre' => 'OtroNombre', 'apellido' => 'Otro']);

    $response = $this->get(route('clientes.index', ['q' => 'UnicoNombre']));

    $response->assertStatus(200);
    $response->assertSee('UnicoNombre');
    $response->assertDontSee('OtroNombre');
});

// ─── CREATE / STORE ───────────────────────────────────────────────────────────

test('formulario de nuevo cliente devuelve 200', function () {
    $response = $this->get(route('clientes.create'));
    $response->assertStatus(200);
});

test('crea cliente con datos válidos', function () {
    $datos = [
        'nombre' => 'Juan',
        'apellido' => 'Pérez',
        'telefono' => '5512345678',
        'correo' => 'juan@example.com',
        'direccion' => 'Calle 1 #123',
    ];

    $response = $this->post(route('clientes.store'), $datos);

    $response->assertRedirect();
    $this->assertDatabaseHas('clientes', ['nombre' => 'Juan', 'apellido' => 'Pérez']);
});

test('validación falla cuando nombre está vacío', function () {
    $response = $this->post(route('clientes.store'), [
        'nombre' => '',
        'apellido' => 'García',
        'telefono' => '5500000000',
    ]);

    $response->assertSessionHasErrors('nombre');
    $this->assertDatabaseMissing('clientes', ['apellido' => 'García']);
});

test('validación falla cuando apellido está vacío', function () {
    $response = $this->post(route('clientes.store'), [
        'nombre' => 'Ana',
        'apellido' => '',
        'telefono' => '5500000000',
    ]);

    $response->assertSessionHasErrors('apellido');
});

test('validación falla cuando teléfono está vacío', function () {
    $response = $this->post(route('clientes.store'), [
        'nombre' => 'Pedro',
        'apellido' => 'Lopez',
        'telefono' => '',
    ]);

    $response->assertSessionHasErrors('telefono');
});

test('validación falla cuando correo tiene formato inválido', function () {
    $response = $this->post(route('clientes.store'), [
        'nombre' => 'María',
        'apellido' => 'Ruiz',
        'telefono' => '5500000000',
        'correo' => 'no-es-un-correo',
    ]);

    $response->assertSessionHasErrors('correo');
});

// ─── SHOW ─────────────────────────────────────────────────────────────────────

test('ver detalle de cliente devuelve 200', function () {
    $cliente = Cliente::factory()->create();

    $response = $this->get(route('clientes.show', $cliente));

    $response->assertStatus(200);
    $response->assertSee($cliente->nombre);
});

// ─── EDIT / UPDATE ────────────────────────────────────────────────────────────

test('formulario de edición devuelve 200', function () {
    $cliente = Cliente::factory()->create();

    $response = $this->get(route('clientes.edit', $cliente));

    $response->assertStatus(200);
    $response->assertSee($cliente->nombre);
});

test('actualiza cliente con datos válidos', function () {
    $cliente = Cliente::factory()->create(['nombre' => 'Antes']);

    $response = $this->put(route('clientes.update', $cliente), [
        'nombre' => 'Después',
        'apellido' => $cliente->apellido,
        'telefono' => $cliente->telefono,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('clientes', ['id_cliente' => $cliente->id_cliente, 'nombre' => 'Después']);
});

// ─── DESTROY ──────────────────────────────────────────────────────────────────

test('elimina cliente sin vehículos', function () {
    $cliente = Cliente::factory()->create();

    $response = $this->delete(route('clientes.destroy', $cliente));

    $response->assertRedirect(route('clientes.index'));
    $this->assertDatabaseMissing('clientes', ['id_cliente' => $cliente->id_cliente]);
});

test('no elimina cliente si tiene vehículos', function () {
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);

    $response = $this->delete(route('clientes.destroy', $cliente));

    $response->assertRedirect(route('clientes.index'));
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('clientes', ['id_cliente' => $cliente->id_cliente]);
});

// ─── RELACIÓN ─────────────────────────────────────────────────────────────────

test('cliente tiene relación hasMany con vehículos', function () {
    $cliente = Cliente::factory()->create();
    Vehiculo::factory()->count(3)->create(['id_cliente' => $cliente->id_cliente]);

    expect($cliente->vehiculos()->count())->toBe(3);
});
