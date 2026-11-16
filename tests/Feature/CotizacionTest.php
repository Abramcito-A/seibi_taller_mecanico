<?php

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
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

/**
 * Borrador con un concepto proveniente del buscador (con instantánea del proveedor) y uno manual.
 *
 * @return array{0: OrdenServicio, 1: Cotizacion, 2: DetalleCotizacion, 3: DetalleCotizacion}
 */
function draftWithSearchedAndManualDetails(): array
{
    $mechanic = quotationUserWithRole('mecanico');
    test()->actingAs($mechanic);
    $order = quotationOrder();
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
        'subtotal' => '650.28',
        'total' => '650.28',
    ]);
    $searched = $quotation->detalles()->create([
        'tipo_concepto' => 'pieza',
        'descripcion' => '4 Bujías NGK G-power',
        'cantidad' => '1.00',
        'precio_unitario' => '450.28',
        'importe' => '450.28',
        'moneda' => 'MXN',
        'tienda_origen' => 'eBay - yes_auto_parts',
        'marca' => 'NGK',
        // URL real guardada desde Google Shopping: contiene "|" y la regla url de Laravel la rechaza.
        'url_shopping' => 'https://www.google.com/search?ibp=oshop&q=bujias&prds=productid:2431288663493831258,rds:PC_2093221156077793499|PROD_PC_2093221156077793499,pvt:hg',
        'imagen_url' => 'https://encrypted-tbn3.gstatic.com/shopping?q=tbn:ANd9GcQ',
        'proveedor' => 'serpapi',
        'proveedor_motor' => 'google_shopping+google_product',
        'proveedor_producto_id' => '2431288663493831258',
        'detalles_tecnicos' => ['features' => [['title' => 'Marca', 'value' => 'NGK']]],
    ]);
    $manual = $quotation->detalles()->create([
        'tipo_concepto' => 'mano_obra',
        'descripcion' => 'Afinación',
        'cantidad' => '1.00',
        'precio_unitario' => '200.00',
        'importe' => '200.00',
        'moneda' => 'MXN',
    ]);

    return [$order, $quotation->refresh(), $searched, $manual];
}

test('editing updates existing concepts, adds and removes concepts, recalculates totals and persists', function () {
    [$order, $quotation, $searched, $manual] = draftWithSearchedAndManualDetails();
    $snapshot = $searched->only(['url_shopping', 'proveedor', 'proveedor_motor', 'proveedor_producto_id', 'detalles_tecnicos', 'imagen_url']);

    $this->put(route('cotizaciones.update', [$order, $quotation]), [
        'titulo' => 'Afinación mayor',
        'tipo' => 'mantenimiento',
        'revision' => $quotation->revision,
        'detalles' => [
            ['id_detalle' => $searched->id_detalle, 'tipo_concepto' => 'pieza', 'descripcion' => '4 Bujías NGK G-power', 'cantidad' => '2', 'precio_unitario' => '430.50', 'imagen_url' => $searched->imagen_url],
            ['tipo_concepto' => 'servicio', 'descripcion' => 'Limpieza de cuerpo de aceleración', 'cantidad' => '1', 'precio_unitario' => '350.00'],
        ],
    ])->assertRedirect(route('cotizaciones.show', [$order, $quotation]))->assertSessionHasNoErrors();

    $quotation->refresh();
    expect($quotation->titulo)->toBe('Afinación mayor')
        ->and($quotation->subtotal)->toBe('1211.00')
        ->and($quotation->total)->toBe('1211.00')
        ->and($quotation->revision)->toBe(2)
        ->and($quotation->detalles()->count())->toBe(2);
    $this->assertModelMissing($manual);
    $searched->refresh();
    expect($searched->cantidad)->toBe('2.00')
        ->and($searched->precio_unitario)->toBe('430.50')
        ->and($searched->importe)->toBe('861.00')
        ->and($searched->only(array_keys($snapshot)))->toBe($snapshot);
    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'descripcion' => 'Limpieza de cuerpo de aceleración',
        'importe' => '350.00',
        'proveedor' => null,
    ]);

    $this->get(route('cotizaciones.show', [$order, $quotation]))
        ->assertOk()
        ->assertSeeInOrder(['4 Bujías NGK G-power', '861.00', 'Limpieza de cuerpo de aceleración', '350.00', '1,211.00 MXN']);
});

test('editing with the real form keeps a stored shopping link that the url rule would reject', function () {
    [$order, $quotation, $searched] = draftWithSearchedAndManualDetails();
    $form = $this->get(route('cotizaciones.edit', [$order, $quotation]))->assertOk()->getContent();
    preg_match_all('/name="(detalles\[\d+\]\[[a-z_]+\]|revision|submission_token|titulo)" value="([^"]*)"/', $form, $inputs, PREG_SET_ORDER);
    $payload = ['tipo' => 'reparacion', 'detalles' => []];
    foreach ($inputs as [, $name, $value]) {
        data_set($payload, str_replace(['[', ']'], ['.', ''], $name), html_entity_decode($value, ENT_QUOTES));
    }
    $payload['detalles'] = array_map(fn (array $detail): array => $detail + ['tipo_concepto' => isset($detail['id_detalle']) && (int) $detail['id_detalle'] === $searched->id_detalle ? 'pieza' : 'mano_obra'], $payload['detalles']);
    $payload['detalles'][0]['precio_unitario'] = '400.00';

    $this->put(route('cotizaciones.update', [$order, $quotation]), $payload)->assertSessionHasNoErrors()->assertRedirect();

    expect($searched->refresh()->precio_unitario)->toBe('400.00')
        ->and($searched->url_shopping)->toContain('|PROD_PC_2093221156077793499')
        ->and($quotation->refresh()->total)->toBe('600.00');
});

test('edit form renders labor hours with decimal steps and shows row errors under the right field', function () {
    [$order, $quotation, , $manual] = draftWithSearchedAndManualDetails();
    $manual->update(['cantidad' => '1.50', 'importe' => '300.00']);

    $this->get(route('cotizaciones.edit', [$order, $quotation]))
        ->assertOk()
        ->assertSee('name="detalles[1][cantidad]" value="1.50" type="number" min="0.01" max="9999.99" step="0.01"', false)
        ->assertDontSee('[url_shopping]', false);

    $this->from(route('cotizaciones.edit', [$order, $quotation]))
        ->followingRedirects()
        ->put(route('cotizaciones.update', [$order, $quotation]), [
            'titulo' => 'Afinación',
            'tipo' => 'mantenimiento',
            'detalles' => [['id_detalle' => $manual->id_detalle, 'tipo_concepto' => 'pieza', 'descripcion' => 'Pieza', 'cantidad' => '1', 'precio_unitario' => '10', 'url_origen' => 'ftp://comercio.example/pieza']],
        ])
        ->assertOk()
        ->assertSee('Revisa los campos marcados para continuar.')
        ->assertSeeInOrder(['name="detalles[0][url_origen]" value="ftp://comercio.example/pieza"', 'Debe ser una dirección web válida que inicie con http:// o https://.'], false);

    expect($quotation->detalles()->count())->toBe(2)
        ->and($manual->refresh()->tipo_concepto)->toBe('mano_obra');
});

test('editing rejects concepts that belong to another quotation without changing anything', function () {
    [$order, $quotation, $searched] = draftWithSearchedAndManualDetails();
    [, , $foreignDetail] = draftWithSearchedAndManualDetails();
    $this->actingAs($quotation->usuario);

    $this->put(route('cotizaciones.update', [$order, $quotation]), [
        'titulo' => 'Intento con concepto ajeno',
        'tipo' => 'reparacion',
        'detalles' => [['id_detalle' => $foreignDetail->id_detalle, 'tipo_concepto' => 'pieza', 'descripcion' => 'Cambiado', 'cantidad' => '1', 'precio_unitario' => '1.00']],
    ])->assertSessionHasErrors(['detalles.0.id_detalle' => 'El concepto no pertenece a esta cotización o está repetido. Recarga la página.']);

    expect($quotation->refresh()->titulo)->not->toBe('Intento con concepto ajeno')
        ->and($quotation->detalles()->count())->toBe(2)
        ->and($foreignDetail->refresh()->descripcion)->toBe('4 Bujías NGK G-power');
});

test('editing a stale form is rejected and a repeated submission does not duplicate concepts', function () {
    [$order, $quotation] = draftWithSearchedAndManualDetails();
    $payload = [
        'titulo' => 'Afinación',
        'tipo' => 'mantenimiento',
        'revision' => 1,
        'submission_token' => '9b2f7c1e-3d4a-4b5c-8d6e-7f8091a2b3c4',
        'detalles' => [['tipo_concepto' => 'servicio', 'descripcion' => 'Concepto nuevo', 'cantidad' => '1', 'precio_unitario' => '100.00']],
    ];

    $this->put(route('cotizaciones.update', [$order, $quotation]), $payload)->assertSessionHasNoErrors();
    $this->put(route('cotizaciones.update', [$order, $quotation]), $payload)
        ->assertRedirect(route('cotizaciones.show', [$order, $quotation]))
        ->assertSessionHas('info', 'Estos cambios ya se habían guardado. Se ignoró el envío repetido del formulario.');
    expect($quotation->detalles()->where('descripcion', 'Concepto nuevo')->count())->toBe(1);

    $this->put(route('cotizaciones.update', [$order, $quotation]), ['submission_token' => '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f'] + $payload)
        ->assertSessionHasErrors('revision');
    expect($quotation->refresh()->revision)->toBe(2)
        ->and($quotation->detalles()->count())->toBe(1);
});
