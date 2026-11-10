<?php

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function quotationUserWithRole(string $roleName): User
{
    $role = Role::query()->firstOrCreate(
        ['nombre' => $roleName],
        ['descripcion' => 'Rol para pruebas de cotizaciones.'],
    );

    return User::factory()->create(['id_rol' => $role->id_rol]);
}

function quotationOrder(): OrdenServicio
{
    $client = Cliente::factory()->create();
    $vehicle = Vehiculo::factory()->create(['id_cliente' => $client->id_cliente]);
    $mechanic = quotationUserWithRole('mecanico');

    return OrdenServicio::factory()->create([
        'id_vehiculo' => $vehicle->id_vehiculo,
        'id_usuario' => $mechanic->id,
    ]);
}

function quotationPayload(?array $details = null): array
{
    return [
        'titulo' => 'Afinación menor',
        'tipo' => 'mantenimiento',
        'descripcion' => 'Servicio completo',
        'observaciones' => 'Vigencia informativa',
        'subtotal' => '99999.99',
        'total' => '99999.99',
        'detalles' => $details ?? [
            [
                'tipo_concepto' => 'pieza',
                'descripcion' => 'Filtro de aceite',
                'cantidad' => '2.00',
                'precio_unitario' => '125.50',
                'moneda' => 'MXN',
                'marca' => 'Marca de prueba',
                'numero_parte' => 'FO-123',
            ],
            [
                'tipo_concepto' => 'mano_obra',
                'descripcion' => 'Cambio y revisión',
                'cantidad' => '1.50',
                'precio_unitario' => '400.00',
                'moneda' => 'MXN',
            ],
        ],
    ];
}

test('mechanic creates multiple quotation types with recalculated totals', function () {
    $mechanic = quotationUserWithRole('mecanico');
    $this->actingAs($mechanic);
    $order = quotationOrder();

    foreach (['mantenimiento', 'reparacion'] as $type) {
        $payload = quotationPayload();
        $payload['tipo'] = $type;
        $payload['titulo'] = $type === 'mantenimiento' ? 'Afinación menor' : 'Cambio de balatas';

        $response = $this->post(route('cotizaciones.store', $order), $payload);

        $response->assertRedirect();
    }

    expect($order->cotizaciones()->count())->toBe(2);
    $subtotal = $order->cotizaciones()->sum('subtotal');
    expect((float) $subtotal)->toBe(1702.0);
    $this->assertDatabaseHas('cotizaciones', ['id_orden' => $order->id_orden, 'titulo' => 'Afinación menor', 'subtotal' => '851.00', 'total' => '851.00']);
});

test('quotation rejects invalid quantities and prices without persistence', function () {
    $mechanic = quotationUserWithRole('mecanico');
    $this->actingAs($mechanic);
    $order = quotationOrder();
    $payload = quotationPayload([[
        'tipo_concepto' => 'servicio',
        'descripcion' => 'Concepto inválido',
        'cantidad' => '0',
        'precio_unitario' => '-1.00',
        'moneda' => 'MXN',
    ]]);

    $response = $this->from(route('cotizaciones.create', $order))
        ->post(route('cotizaciones.store', $order), $payload);

    $response->assertRedirect(route('cotizaciones.create', $order))
        ->assertSessionHasErrors(['detalles.0.cantidad', 'detalles.0.precio_unitario']);
    expect(Cotizacion::query()->count())->toBe(0);
});

test('mechanic edits and deletes draft quotations but historical quotations remain immutable', function () {
    $mechanic = quotationUserWithRole('mecanico');
    $this->actingAs($mechanic);
    $order = quotationOrder();

    $this->post(route('cotizaciones.store', $order), quotationPayload())->assertRedirect();
    $quotation = $order->cotizaciones()->firstOrFail();

    $updated = quotationPayload([[
        'tipo_concepto' => 'servicio',
        'descripcion' => 'Concepto actualizado',
        'cantidad' => '2.00',
        'precio_unitario' => '250.00',
        'moneda' => 'MXN',
    ]]);
    $updated['titulo'] = 'Trabajo corregido';
    $this->put(route('cotizaciones.update', [$order, $quotation]), $updated)->assertRedirect();

    $quotation->refresh();
    expect($quotation->detalles()->count())->toBe(1)
        ->and((float) $quotation->subtotal)->toBe(500.0);
    $this->assertDatabaseHas('detalles_cotizacion', ['id_cotizacion' => $quotation->id_cotizacion, 'descripcion' => 'Concepto actualizado', 'importe' => '500.00']);

    $quotation->update(['estado' => 'enviada', 'decision_cliente' => 'pendiente']);
    $this->get(route('cotizaciones.edit', [$order, $quotation]))->assertForbidden();
    $this->put(route('cotizaciones.update', [$order, $quotation]), quotationPayload())->assertForbidden();
    $this->delete(route('cotizaciones.destroy', [$order, $quotation]))->assertForbidden();
    $this->assertModelExists($quotation);

    $draft = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    $this->delete(route('cotizaciones.destroy', [$order, $draft]))->assertRedirect();
    $this->assertModelMissing($draft);
});

test('administrator and receptionist may consult but cannot mutate quotations', function () {
    $order = quotationOrder();
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $order->id_usuario,
        'estado' => 'borrador',
    ]);

    foreach (['administrador', 'recepcionista'] as $roleName) {
        $user = quotationUserWithRole($roleName);
        $this->actingAs($user);

        $this->get(route('cotizaciones.index', $order))->assertOk();
        $this->get(route('cotizaciones.show', [$order, $quotation]))->assertOk();
        $this->get(route('cotizaciones.create', $order))->assertForbidden();
        $this->post(route('cotizaciones.store', $order), quotationPayload())->assertForbidden();
        $this->get(route('cotizaciones.edit', [$order, $quotation]))->assertForbidden();
        $this->put(route('cotizaciones.update', [$order, $quotation]), quotationPayload())->assertForbidden();
        $this->delete(route('cotizaciones.destroy', [$order, $quotation]))->assertForbidden();
    }
});

test('piece and service quantities must be integer while labor accepts decimal hours', function () {
    $mechanic = quotationUserWithRole('mecanico');
    $this->actingAs($mechanic);
    $order = quotationOrder();

    $piece = quotationPayload([[
        'tipo_concepto' => 'pieza',
        'descripcion' => 'Filtro',
        'cantidad' => '1.5',
        'precio_unitario' => '100.00',
        'moneda' => 'MXN',
    ]]);
    $this->from(route('cotizaciones.create', $order))
        ->post(route('cotizaciones.store', $order), $piece)
        ->assertSessionHasErrors(['detalles.0.cantidad']);

    $labor = quotationPayload([[
        'tipo_concepto' => 'mano_obra',
        'descripcion' => 'Diagnóstico',
        'cantidad' => '1.25',
        'precio_unitario' => '400.00',
        'moneda' => 'MXN',
    ]]);
    $this->post(route('cotizaciones.store', $order), $labor)->assertRedirect();
    $this->assertDatabaseHas('detalles_cotizacion', [
        'cantidad' => '1.25',
        'importe' => '500.00',
    ]);
});

test('quotation is isolated to its service order and preserves commercial detail data', function () {
    $mechanic = quotationUserWithRole('mecanico');
    $this->actingAs($mechanic);
    $order = quotationOrder();
    $otherOrder = quotationOrder();

    $payload = quotationPayload([[
        'tipo_concepto' => 'pieza',
        'descripcion' => 'Refacción manual',
        'cantidad' => '1.00',
        'precio_unitario' => '349.99',
        'moneda' => 'MXN',
        'tienda_origen' => 'Tienda de prueba',
        'marca' => 'Marca estática',
        'numero_parte' => 'PN-55',
        'url_origen' => 'https://example.com/publicacion',
    ]]);

    $this->post(route('cotizaciones.store', $order), $payload)->assertRedirect();
    $quotation = $order->cotizaciones()->firstOrFail();
    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'importe' => '349.99',
        'tienda_origen' => 'Tienda de prueba',
        'marca' => 'Marca estática',
        'numero_parte' => 'PN-55',
        'url_origen' => 'https://example.com/publicacion',
        'moneda' => 'MXN',
    ]);

    $this->get(route('cotizaciones.show', [$otherOrder, $quotation]))->assertNotFound();
});

test('quotation consultation shows a secure purchase link only for a valid saved publication URL', function () {
    $mechanic = quotationUserWithRole('mecanico');
    $order = quotationOrder();
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    $quotation->detalles()->create([
        'tipo_concepto' => 'pieza',
        'descripcion' => 'Filtro con enlace',
        'cantidad' => '1.00',
        'precio_unitario' => '300.00',
        'importe' => '300.00',
        'moneda' => 'MXN',
        'url_origen' => 'https://example.com/filtro',
    ]);
    $quotation->detalles()->create([
        'tipo_concepto' => 'pieza',
        'descripcion' => 'Filtro sin enlace seguro',
        'cantidad' => '1.00',
        'precio_unitario' => '200.00',
        'importe' => '200.00',
        'moneda' => 'MXN',
        'url_origen' => 'javascript:alert(1)',
    ]);

    $this->actingAs($mechanic);
    $this->get(route('cotizaciones.show', [$order, $quotation]))
        ->assertOk()
        ->assertSee('https://example.com/filtro', false)
        ->assertSee('Comprar', false)
        ->assertSee('Enlace de compra no disponible')
        ->assertDontSee('javascript:alert(1)', false);
});
