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

test('lista de vehículos devuelve 200', function () {
    $response = $this->get(route('vehiculos.index'));
    $response->assertStatus(200);
});

test('lista de vehículos muestra registros existentes', function () {
    $vehiculo = Vehiculo::factory()->create(['marca' => 'Toyota', 'modelo' => 'Corolla']);

    $response = $this->get(route('vehiculos.index'));

    $response->assertStatus(200);
    $response->assertSee('Toyota');
});

test('búsqueda de vehículos filtra por marca', function () {
    Vehiculo::factory()->create(['marca' => 'Toyota', 'modelo' => 'Corolla']);
    Vehiculo::factory()->create(['marca' => 'Nissan', 'modelo' => 'Sentra']);

    $response = $this->get(route('vehiculos.index', ['q' => 'Toyota']));

    $response->assertStatus(200);
    $response->assertSee('Toyota');
    $response->assertDontSee('Nissan');
});

test('filtro por cliente funciona', function () {
    $clienteA = Cliente::factory()->create();
    $clienteB = Cliente::factory()->create();
    Vehiculo::factory()->create(['id_cliente' => $clienteA->id_cliente, 'marca' => 'Toyota']);
    Vehiculo::factory()->create(['id_cliente' => $clienteB->id_cliente, 'marca' => 'Nissan']);

    $response = $this->get(route('vehiculos.index', ['cliente_id' => $clienteA->id_cliente]));

    $response->assertStatus(200);
    $response->assertSee('Toyota');
    $response->assertDontSee('Nissan');
});

// ─── CREATE / STORE ───────────────────────────────────────────────────────────

test('formulario de nuevo vehículo devuelve 200', function () {
    $response = $this->get(route('vehiculos.create'));
    $response->assertStatus(200);
});

test('crea vehículo con datos válidos', function () {
    $cliente = Cliente::factory()->create();

    $datos = [
        'id_cliente' => $cliente->id_cliente,
        'marca' => 'Toyota',
        'modelo' => 'Corolla',
        'anio' => 2020,
        'version' => 'LE',
        'placas' => 'ABC-1234',
    ];

    $response = $this->post(route('vehiculos.store'), $datos);

    $response->assertRedirect();
    $this->assertDatabaseHas('vehiculos', ['marca' => 'Toyota', 'modelo' => 'Corolla']);
});

test('validación falla cuando cliente no existe', function () {
    $response = $this->post(route('vehiculos.store'), [
        'id_cliente' => 9999,
        'marca' => 'Ford',
        'modelo' => 'Focus',
        'anio' => 2020,
    ]);

    $response->assertSessionHasErrors('id_cliente');
});

test('validación falla cuando marca está vacía', function () {
    $cliente = Cliente::factory()->create();

    $response = $this->post(route('vehiculos.store'), [
        'id_cliente' => $cliente->id_cliente,
        'marca' => '',
        'modelo' => 'Focus',
        'anio' => 2020,
    ]);

    $response->assertSessionHasErrors('marca');
});

test('validación falla cuando modelo está vacío', function () {
    $cliente = Cliente::factory()->create();

    $response = $this->post(route('vehiculos.store'), [
        'id_cliente' => $cliente->id_cliente,
        'marca' => 'Ford',
        'modelo' => '',
        'anio' => 2020,
    ]);

    $response->assertSessionHasErrors('modelo');
});

test('validación falla cuando año es inválido', function () {
    $cliente = Cliente::factory()->create();

    $response = $this->post(route('vehiculos.store'), [
        'id_cliente' => $cliente->id_cliente,
        'marca' => 'Ford',
        'modelo' => 'Focus',
        'anio' => 1800,
    ]);

    $response->assertSessionHasErrors('anio');
});

// ─── SHOW ─────────────────────────────────────────────────────────────────────

test('ver detalle de vehículo devuelve 200', function () {
    $vehiculo = Vehiculo::factory()->create();

    $response = $this->get(route('vehiculos.show', $vehiculo));

    $response->assertStatus(200);
    $response->assertSee($vehiculo->marca);
});

// ─── EDIT / UPDATE ────────────────────────────────────────────────────────────

test('formulario de edición de vehículo devuelve 200', function () {
    $vehiculo = Vehiculo::factory()->create();

    $response = $this->get(route('vehiculos.edit', $vehiculo));

    $response->assertStatus(200);
    $response->assertSee($vehiculo->marca);
});

test('actualiza vehículo con datos válidos', function () {
    $vehiculo = Vehiculo::factory()->create(['marca' => 'Honda']);
    $cliente = Cliente::factory()->create();

    $response = $this->put(route('vehiculos.update', $vehiculo), [
        'id_cliente' => $cliente->id_cliente,
        'marca' => 'Mazda',
        'modelo' => $vehiculo->modelo,
        'anio' => $vehiculo->anio,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('vehiculos', ['id_vehiculo' => $vehiculo->id_vehiculo, 'marca' => 'Mazda']);
});

// ─── DESTROY ──────────────────────────────────────────────────────────────────

test('elimina vehículo sin órdenes de servicio', function () {
    $vehiculo = Vehiculo::factory()->create();

    $response = $this->delete(route('vehiculos.destroy', $vehiculo));

    $response->assertRedirect(route('vehiculos.index'));
    $this->assertDatabaseMissing('vehiculos', ['id_vehiculo' => $vehiculo->id_vehiculo]);
});

// ─── RELACIÓN ─────────────────────────────────────────────────────────────────

test('vehículo pertenece a un cliente', function () {
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);

    expect($vehiculo->cliente->id_cliente)->toBe($cliente->id_cliente);
});

test('cliente tiene vehículos correctamente relacionados', function () {
    $cliente = Cliente::factory()->create();
    Vehiculo::factory()->count(2)->create(['id_cliente' => $cliente->id_cliente]);

    $response = $this->get(route('clientes.show', $cliente));

    $response->assertStatus(200);
});
