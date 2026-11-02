<?php

use App\Models\Cliente;
use App\Models\Diagnostico;
use App\Models\Evidencia;
use App\Models\OrdenServicio;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('visitors are redirected away from order list and reception form', function () {
    $this->get(route('ordenes.index'))->assertRedirect(route('login'));
    $this->get(route('ordenes.create'))->assertRedirect(route('login'));
});

test('reception creates a received order with server identity and initial history', function () {
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);
    $recepcionista = orderModuleUser('recepcionista');
    $payload = makeOrderReceptionPayload($cliente, $vehiculo);
    $payload['id_usuario'] = 999999;
    $payload['folio'] = 'FOLIO-FORZADO';
    $payload['estado'] = 'cerrada';

    $response = $this->actingAs($recepcionista)->post(route('ordenes.store'), $payload);

    $orden = OrdenServicio::query()->where('id_vehiculo', $vehiculo->id_vehiculo)->firstOrFail();
    $response->assertRedirect(route('ordenes.show', $orden))
        ->assertSessionHas('success', "Recepción guardada. Folio {$orden->folio}.");

    expect($orden->estado)->toBe('recibida')
        ->and($orden->id_usuario)->toBe($recepcionista->id)
        ->and($orden->folio)->not->toBe('FOLIO-FORZADO')
        ->and($orden->folio)->toMatch('/^OS-\d{8}-[A-Z0-9]{10}$/')
        ->and($orden->fecha_ingreso)->not->toBeNull()
        ->and($orden->fecha_cierre)->toBeNull();

    $this->assertDatabaseHas('historial_estados', [
        'id_orden' => $orden->id_orden,
        'estado_anterior' => null,
        'estado_nuevo' => 'recibida',
    ]);
});

test('reception limits the number of uploaded photos', function () {
    Storage::fake('private');
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);
    $photos = collect(range(1, 6))
        ->map(fn (int $number) => UploadedFile::fake()->image("photo-{$number}.jpg"))
        ->all();

    $this->actingAs(orderModuleUser('recepcionista'))
        ->from(route('ordenes.create', ['cliente_id' => $cliente->id_cliente]))
        ->post(route('ordenes.store'), [
            ...makeOrderReceptionPayload($cliente, $vehiculo),
            'fotografias' => $photos,
        ])
        ->assertSessionHasErrors('fotografias');

    $this->assertDatabaseCount('ordenes_servicio', 0);
    expect(Storage::disk('private')->allFiles())->toBeEmpty();
});

test('reception removes stored photos and rolls back if evidence metadata fails', function () {
    Storage::fake('private');
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);

    Evidencia::creating(static function (Evidencia $evidencia): void {
        throw new RuntimeException('Metadata write failed.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(orderModuleUser('recepcionista'))->post(route('ordenes.store'), [
        ...makeOrderReceptionPayload($cliente, $vehiculo),
        'fotografias' => [UploadedFile::fake()->image('initial.jpg')],
    ]))->toThrow(RuntimeException::class, 'Metadata write failed.');

    $this->assertDatabaseCount('ordenes_servicio', 0);
    $this->assertDatabaseCount('evidencias', 0);
    expect(Storage::disk('private')->allFiles())->toBeEmpty();
});

test('reception rejects a vehicle that belongs to a different customer', function () {
    $clienteSeleccionado = Cliente::factory()->create();
    $otroCliente = Cliente::factory()->create();
    $vehiculoAjeno = Vehiculo::factory()->create(['id_cliente' => $otroCliente->id_cliente]);

    $response = $this->actingAs(orderModuleUser('recepcionista'))
        ->from(route('ordenes.create', ['cliente_id' => $clienteSeleccionado->id_cliente]))
        ->post(route('ordenes.store'), makeOrderReceptionPayload($clienteSeleccionado, $vehiculoAjeno));

    $response->assertSessionHasErrors('id_vehiculo');
    $this->assertDatabaseCount('ordenes_servicio', 0);
});

test('reception form lists only vehicles for the selected customer', function () {
    $selectedClient = Cliente::factory()->create();
    $otherClient = Cliente::factory()->create();
    $selectedVehicle = Vehiculo::factory()->create(['id_cliente' => $selectedClient->id_cliente]);
    $otherVehicle = Vehiculo::factory()->create(['id_cliente' => $otherClient->id_cliente]);

    $this->actingAs(orderModuleUser('recepcionista'))
        ->get(route('ordenes.create', ['cliente_id' => $selectedClient->id_cliente]))
        ->assertOk()
        ->assertSee($selectedVehicle->marca.' '.$selectedVehicle->modelo)
        ->assertDontSee($otherVehicle->marca.' '.$otherVehicle->modelo);
});

test('each new reception gets a unique server-generated folio', function () {
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);
    $this->actingAs(orderModuleUser('administrador'));

    $this->post(route('ordenes.store'), makeOrderReceptionPayload($cliente, $vehiculo))->assertRedirect();
    $this->post(route('ordenes.store'), makeOrderReceptionPayload($cliente, $vehiculo))->assertRedirect();

    expect(OrdenServicio::query()->count())->toBe(2)
        ->and(OrdenServicio::query()->distinct()->count('folio'))->toBe(2);
});

test('orders can be searched by folio client vehicle and plates and filtered by state', function () {
    $cliente = Cliente::factory()->create([
        'nombre' => 'ClienteBusqueda',
        'apellido' => 'Expediente',
        'telefono' => '5551010101',
        'correo' => 'busqueda@example.com',
    ]);
    $vehiculo = Vehiculo::factory()->create([
        'id_cliente' => $cliente->id_cliente,
        'marca' => 'MarcaBusqueda',
        'modelo' => 'ModeloBusqueda',
        'placas' => 'BUS-1234',
    ]);
    $orden = OrdenServicio::factory()->create([
        'id_vehiculo' => $vehiculo->id_vehiculo,
        'folio' => 'OS-UNICO-RECEPCION',
        'estado' => 'recibida',
    ]);
    $otraOrden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $this->actingAs(orderModuleUser('administrador'));

    foreach (['OS-UNICO-RECEPCION', 'ClienteBusqueda', '5551010101', 'busqueda@example.com', 'MarcaBusqueda', 'ModeloBusqueda', 'BUS-1234'] as $term) {
        $this->get(route('ordenes.index', ['q' => $term]))
            ->assertOk()
            ->assertSee($orden->folio)
            ->assertDontSee($otraOrden->folio);
    }

    $this->get(route('ordenes.index', ['estado' => 'en_diagnostico']))
        ->assertOk()
        ->assertSee($otraOrden->folio)
        ->assertDontSee($orden->folio);
});

test('administrators can create and start an order using the available workflow', function () {
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);
    $this->actingAs(orderModuleUser('administrador'));

    $this->post(route('ordenes.store'), makeOrderReceptionPayload($cliente, $vehiculo))
        ->assertRedirect();

    $orden = OrdenServicio::query()->firstOrFail();
    $this->post(route('ordenes.iniciar-diagnostico', $orden))
        ->assertRedirect(route('ordenes.show', $orden));

    expect($orden->fresh()->estado)->toBe('en_diagnostico');
});

test('reception stores multiple private photos and metadata without public visibility', function () {
    Storage::fake('private');
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);

    $response = $this->actingAs(orderModuleUser('recepcionista'))->post(route('ordenes.store'), [
        ...makeOrderReceptionPayload($cliente, $vehiculo),
        'fotografias' => [
            UploadedFile::fake()->image('frente.jpg'),
            UploadedFile::fake()->image('costado.png'),
        ],
    ]);

    $orden = OrdenServicio::query()->firstOrFail();
    $evidencias = $orden->evidencias()->orderBy('id_evidencia')->get();

    $response->assertRedirect(route('ordenes.show', $orden));
    expect($evidencias)->toHaveCount(2);

    foreach ($evidencias as $evidencia) {
        expect($evidencia->etapa)->toBe(Evidencia::ETAPA_RECEPCION)
            ->and($evidencia->visible_cliente)->toBeFalse()
            ->and($evidencia->id_usuario)->toBe(auth()->id())
            ->and($evidencia->ruta_archivo)->toStartWith('ordenes/'.$orden->id_orden.'/recepcion/');

        Storage::disk('private')->assertExists($evidencia->ruta_archivo);
        $this->assertDatabaseHas('evidencias', [
            'id_evidencia' => $evidencia->id_evidencia,
            'id_orden' => $orden->id_orden,
            'tipo_archivo' => 'imagen',
            'visible_cliente' => false,
        ]);
    }
});

test('reception rejects non-image files and images larger than five megabytes', function (UploadedFile $photo) {
    Storage::fake('private');
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create(['id_cliente' => $cliente->id_cliente]);

    $response = $this->actingAs(orderModuleUser('recepcionista'))
        ->from(route('ordenes.create', ['cliente_id' => $cliente->id_cliente]))
        ->post(route('ordenes.store'), [
            ...makeOrderReceptionPayload($cliente, $vehiculo),
            'fotografias' => [$photo],
        ]);

    $response->assertSessionHasErrors('fotografias.0');
    $this->assertDatabaseCount('ordenes_servicio', 0);
    expect(Storage::disk('private')->allFiles())->toBeEmpty();
})->with([
    'document is rejected' => fn () => UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'),
    'oversized photo is rejected' => fn () => UploadedFile::fake()->image('large.jpg')->size(5121),
]);

test('authorized staff can stream a private photo only through its matching order', function () {
    Storage::fake('private');
    $orden = OrdenServicio::factory()->create();
    $evidencia = Evidencia::factory()->create([
        'id_orden' => $orden->id_orden,
        'ruta_archivo' => 'ordenes/'.$orden->id_orden.'/recepcion/'.fake()->uuid().'.jpg',
        'etapa' => Evidencia::ETAPA_RECEPCION,
        'visible_cliente' => false,
    ]);
    Storage::disk('private')->put($evidencia->ruta_archivo, 'private-image-content');

    $response = $this->actingAs(orderModuleUser('mecanico'))
        ->get(route('ordenes.evidencias.show', [$orden, $evidencia]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('visitors and evidence belonging to another order cannot access a private photo', function () {
    Storage::fake('private');
    $orden = OrdenServicio::factory()->create();
    $otraOrden = OrdenServicio::factory()->create();
    $evidencia = Evidencia::factory()->create([
        'id_orden' => $otraOrden->id_orden,
        'ruta_archivo' => 'ordenes/'.$otraOrden->id_orden.'/recepcion/'.fake()->uuid().'.jpg',
        'etapa' => Evidencia::ETAPA_RECEPCION,
    ]);
    Storage::disk('private')->put($evidencia->ruta_archivo, 'private-image-content');

    $this->get(route('ordenes.evidencias.show', [$otraOrden, $evidencia]))
        ->assertRedirect(route('login'));

    $this->actingAs(orderModuleUser('mecanico'))
        ->get(route('ordenes.evidencias.show', [$orden, $evidencia]))
        ->assertNotFound();
});

test('evidence routes reject stored paths containing traversal segments', function () {
    Storage::fake('private');
    $orden = OrdenServicio::factory()->create();
    $evidencia = Evidencia::factory()->create([
        'id_orden' => $orden->id_orden,
        'ruta_archivo' => 'ordenes/'.$orden->id_orden.'/recepcion/../../private/secret.jpg',
        'etapa' => Evidencia::ETAPA_RECEPCION,
    ]);

    $this->actingAs(orderModuleUser('mecanico'))
        ->get(route('ordenes.evidencias.show', [$orden, $evidencia]))
        ->assertNotFound();
});

test('private evidence storage is outside the Laravel-served local disk', function () {
    expect(config('filesystems.disks.private.root'))
        ->not->toBe(config('filesystems.disks.local.root'));
});

test('closing an order records its closing date without overwriting it', function () {
    $orden = OrdenServicio::factory()->create([
        'estado' => 'cerrada',
        'fecha_cierre' => null,
    ]);

    expect($orden->fecha_cierre)->not->toBeNull();

    $fechaOriginal = $orden->fecha_cierre;
    $orden->forceFill(['observaciones' => 'Actualización sin cambiar el cierre'])->save();

    expect($orden->fresh()->fecha_cierre->equalTo($fechaOriginal))->toBeTrue();
});

test('mechanics can view received orders and explicitly start diagnosis with history', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'recibida']);
    $mecanico = orderModuleUser('mecanico');

    $this->actingAs($mecanico)
        ->get(route('ordenes.show', $orden))
        ->assertOk()
        ->assertSee($orden->folio)
        ->assertSee('Iniciar diagnóstico');

    $this->post(route('ordenes.iniciar-diagnostico', $orden))
        ->assertRedirect(route('ordenes.show', $orden));

    expect($orden->fresh()->estado)->toBe('en_diagnostico');
    $this->assertDatabaseHas('historial_estados', [
        'id_orden' => $orden->id_orden,
        'id_usuario' => $mecanico->id,
        'estado_anterior' => 'recibida',
        'estado_nuevo' => 'en_diagnostico',
    ]);
});

test('mechanics cannot create or edit reception data directly', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'recibida']);
    $this->actingAs(orderModuleUser('mecanico'));

    $this->get(route('ordenes.create'))->assertForbidden();
    $this->post(route('ordenes.store'), makeOrderReceptionPayload($orden->vehiculo->cliente, $orden->vehiculo))
        ->assertForbidden();
    $this->get(route('ordenes.edit', $orden))->assertForbidden();
    $this->put(route('ordenes.update', $orden), makeOrderReceptionPayload($orden->vehiculo->cliente, $orden->vehiculo))
        ->assertForbidden();
});

test('receptionists cannot start diagnosis or change original data after diagnosis starts', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'recibida']);
    $this->actingAs(orderModuleUser('recepcionista'));

    $this->post(route('ordenes.iniciar-diagnostico', $orden))->assertForbidden();
    $this->put(route('ordenes.update', $orden), makeOrderReceptionPayload($orden->vehiculo->cliente, $orden->vehiculo))
        ->assertRedirect();

    $orden->forceFill(['estado' => 'en_diagnostico'])->save();
    auth()->forgetGuards();
    $this->actingAs(orderModuleUser('recepcionista'))
        ->put(route('ordenes.update', $orden), makeOrderReceptionPayload($orden->vehiculo->cliente, $orden->vehiculo))
        ->assertForbidden();
});

test('orders have no deletion endpoint and remain in vehicle history', function () {
    $orden = OrdenServicio::factory()->create();
    $this->actingAs(orderModuleUser('administrador'));

    $this->delete('/ordenes/'.$orden->id_orden)->assertMethodNotAllowed();
    $this->assertModelExists($orden);
    expect($orden->vehiculo->ordenesServicio()->count())->toBe(1);
});

test('mechanics can register a technical finding and private diagnostic evidence', function () {
    Storage::fake('private');
    $mecanico = orderModuleUser('mecanico');
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $uuid = (string) Str::uuid();

    $this->actingAs($mecanico)->post(route('diagnosticos.store', $orden), [
        'envio_uuid' => $uuid,
        'titulo' => 'Fuga de aceite',
        'descripcion' => 'Se detecta fuga en la junta de la tapa.',
        'severidad' => 'alta',
        'recomendacion' => 'Sustituir la junta y verificar la presión.',
        'fotografias' => [UploadedFile::fake()->image('fuga.jpg')],
    ])->assertRedirect();

    $diagnostico = $orden->diagnosticos()->firstOrFail();
    expect($diagnostico->id_usuario)->toBe($mecanico->id)
        ->and($diagnostico->severidad)->toBe('alta');

    $evidencia = Evidencia::query()->where('id_diagnostico', $diagnostico->id_diagnostico)->firstOrFail();
    expect($evidencia->etapa)->toBe('diagnostico')
        ->and($evidencia->visible_cliente)->toBeFalse()
        ->and(Storage::disk('private')->exists($evidencia->ruta_archivo))->toBeTrue();
});

test('technicians cannot start closed or cancelled orders', function () {
    $mecanico = orderModuleUser('mecanico');

    foreach (['cerrada', 'cancelada'] as $estado) {
        $orden = OrdenServicio::factory()->create(['estado' => $estado]);
        $this->actingAs($mecanico)
            ->post(route('ordenes.iniciar-diagnostico', $orden))
            ->assertForbidden();
        expect($orden->fresh()->estado)->toBe($estado);
    }
});

test('receptionists cannot create or edit technical findings', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $recepcionista = orderModuleUser('recepcionista');

    $this->actingAs($recepcionista)
        ->get(route('diagnosticos.create', $orden))
        ->assertForbidden();

    $this->post(route('diagnosticos.store', $orden), [
        'titulo' => 'No autorizado',
        'descripcion' => 'Intento técnico.',
        'severidad' => 'media',
    ])->assertForbidden();
});

test('diagnostic evidence and create routes reject invalid photos and unauthorized roles', function () {
    Storage::fake('private');
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $mecanico = orderModuleUser('mecanico');

    $this->actingAs($mecanico)
        ->from(route('diagnosticos.create', $orden))
        ->post(route('diagnosticos.store', $orden), [
            'envio_uuid' => (string) Str::uuid(),
            'titulo' => 'Archivo inválido',
            'descripcion' => 'Prueba de validación.',
            'severidad' => 'baja',
            'fotografias' => [UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream')],
        ])
        ->assertSessionHasErrors('fotografias.0');

    expect($orden->diagnosticos()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBeEmpty();
});

test('one order can keep multiple findings and diagnostic evidence is role protected', function () {
    Storage::fake('private');
    $mecanico = orderModuleUser('mecanico');
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);

    foreach ([
        ['titulo' => 'Balatas delanteras', 'descripcion' => 'Desgaste avanzado.', 'severidad' => 'alta'],
        ['titulo' => 'Filtro de aire', 'descripcion' => 'Elemento deteriorado.', 'severidad' => 'media'],
    ] as $finding) {
        $this->actingAs($mecanico)->post(route('diagnosticos.store', $orden), [
            ...$finding,
            'envio_uuid' => (string) Str::uuid(),
        ])->assertRedirect();
    }

    expect($orden->diagnosticos()->count())->toBe(2);

    $diagnostico = $orden->diagnosticos()->firstOrFail();
    $this->actingAs($mecanico)
        ->post(route('diagnosticos.store', $orden), [
            ...$diagnostico->only(['titulo', 'descripcion', 'severidad']),
            'envio_uuid' => (string) Str::uuid(),
            'fotografias' => [UploadedFile::fake()->image('tecnica.png')],
        ])
        ->assertRedirect();

    $evidencia = Evidencia::query()->where('etapa', 'diagnostico')->latest('id_evidencia')->first();
    expect($evidencia)->not->toBeNull();

    $recepcionista = orderModuleUser('recepcionista');
    $this->actingAs($recepcionista)
        ->get(route('ordenes.evidencias.show', [$orden, $evidencia]))
        ->assertForbidden();

    $administrador = orderModuleUser('administrador');
    $this->actingAs($administrador)
        ->get(route('ordenes.evidencias.show', [$orden, $evidencia]))
        ->assertOk();
});

test('order detail keeps its fallback button and diagnosis form uses an explicit order link', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $mecanico = orderModuleUser('mecanico');

    $this->actingAs($mecanico)
        ->get(route('ordenes.show', $orden))
        ->assertOk()
        ->assertSee('data-back-button', false)
        ->assertSee('data-fallback="'.route('ordenes.index').'"', false);

    $this->get(route('diagnosticos.create', $orden))
        ->assertOk()
        ->assertSee('Volver al expediente')
        ->assertSee(route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos']), false);
});

test('diagnosis post redirect get returns to diagnosis section and highlight', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $uuid = (string) Str::uuid();

    $response = $this->actingAs(orderModuleUser('mecanico'))
        ->post(route('diagnosticos.store', $orden), [
            'envio_uuid' => $uuid,
            'titulo' => 'Fuga detectada',
            'descripcion' => 'Descripción técnica',
            'severidad' => 'alta',
        ]);

    $diagnostico = Diagnostico::query()->where('envio_uuid', $uuid)->firstOrFail();
    $response->assertRedirect(route('ordenes.show', [
        'orden' => $orden,
        'seccion' => 'diagnosticos',
        'destacado' => $diagnostico->id_diagnostico,
    ]).'#diagnostico-'.$diagnostico->id_diagnostico);
    $response->assertSessionHas('success', 'Hallazgo registrado correctamente');
});

test('reusing the same submission uuid does not create a duplicate diagnosis', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $uuid = (string) Str::uuid();
    $payload = [
        'envio_uuid' => $uuid,
        'titulo' => 'Hallazgo repetido',
        'descripcion' => 'Mismo contenido',
        'severidad' => 'media',
    ];
    $mecanico = orderModuleUser('mecanico');

    $this->actingAs($mecanico)->post(route('diagnosticos.store', $orden), $payload)->assertRedirect();
    $this->actingAs($mecanico)->post(route('diagnosticos.store', $orden), $payload)
        ->assertRedirect()
        ->assertSessionHas('info', 'Este formulario ya había sido procesado. No se creó otro hallazgo')
        ->assertSessionMissing('success');

    expect(Diagnostico::query()->where('envio_uuid', $uuid)->count())->toBe(1);
});

test('changed content with the same uuid requires explicit confirmation before creating a new finding', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $mecanico = orderModuleUser('mecanico');
    $uuid = (string) Str::uuid();

    $this->actingAs($mecanico)->post(route('diagnosticos.store', $orden), [
        'envio_uuid' => $uuid,
        'titulo' => 'Título original',
        'descripcion' => 'Descripción original',
        'severidad' => 'media',
        'recomendacion' => 'Revisión original',
    ])->assertRedirect();

    $response = $this->actingAs($mecanico)->post(route('diagnosticos.store', $orden), [
        'envio_uuid' => $uuid,
        'titulo' => 'Título modificado',
        'descripcion' => 'Descripción modificada',
        'severidad' => 'alta',
        'recomendacion' => 'Nueva recomendación',
    ]);

    $response->assertOk()
        ->assertSee('Este formulario ya fue procesado anteriormente. Tus nuevos cambios todavía no se han guardado.')
        ->assertViewHas('token');

    expect(Diagnostico::query()->where('id_orden', $orden->id_orden)->count())->toBe(1);

    $token = $response->viewData('token');
    $this->actingAs($mecanico)
        ->post(route('diagnosticos.confirm-new', [$orden, $token]))
        ->assertRedirect();

    expect(Diagnostico::query()->where('id_orden', $orden->id_orden)->count())->toBe(2)
        ->and(Diagnostico::query()->where('id_orden', $orden->id_orden)->where('titulo', 'Título modificado')->exists())->toBeTrue();
});

test('different submission uuids allow identical diagnosis content', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $mecanico = orderModuleUser('mecanico');
    $payload = [
        'titulo' => 'Contenido idéntico',
        'descripcion' => 'Descripción idéntica',
        'severidad' => 'baja',
    ];

    $this->actingAs($mecanico)->post(route('diagnosticos.store', $orden), [...$payload, 'envio_uuid' => (string) Str::uuid()]);
    $this->actingAs($mecanico)->post(route('diagnosticos.store', $orden), [...$payload, 'envio_uuid' => (string) Str::uuid()]);

    expect(Diagnostico::query()->where('id_orden', $orden->id_orden)->count())->toBe(2);
});

test('validation errors preserve the diagnosis submission uuid', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $uuid = (string) Str::uuid();

    $this->actingAs(orderModuleUser('mecanico'))
        ->from(route('diagnosticos.create', $orden))
        ->post(route('diagnosticos.store', $orden), [
            'envio_uuid' => $uuid,
            'titulo' => '',
            'descripcion' => '',
            'severidad' => 'baja',
        ])
        ->assertSessionHasErrors(['titulo', 'descripcion'])
        ->assertSessionHasInput('envio_uuid', $uuid);
});

test('diagnosis tab paginates five findings and preserves search and severity filters', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $mecanico = orderModuleUser('mecanico');

    foreach (range(1, 12) as $number) {
        Diagnostico::query()->create([
            'id_orden' => $orden->id_orden,
            'id_usuario' => $mecanico->id,
            'titulo' => 'Filtro especial '.$number,
            'descripcion' => 'Descripción '.$number,
            'severidad' => 'alta',
        ]);
    }

    $this->actingAs($mecanico)
        ->get(route('ordenes.show', [
            'orden' => $orden,
            'seccion' => 'diagnosticos',
            'diagnostico_q' => 'Filtro especial',
            'severidad' => 'alta',
        ]))
        ->assertOk()
        ->assertSee('Filtro especial 1')
        ->assertSee('Mostrando 1–5 de 12 hallazgos')
        ->assertViewHas('diagnosticos', function ($diagnosticos) {
            return $diagnosticos->count() === 5 && $diagnosticos->total() === 12;
        });

    $this->actingAs($mecanico)
        ->get(route('ordenes.show', [
            'orden' => $orden,
            'seccion' => 'diagnosticos',
            'diagnostico_q' => 'Filtro especial',
            'severidad' => 'alta',
            'page' => 2,
        ]))
        ->assertOk()
        ->assertSee('Mostrando 6–10 de 12 hallazgos')
        ->assertSee('Página 2 de 3');
});

test('diagnosis creation form has an explicit order fallback and submission uuid', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);

    $this->actingAs(orderModuleUser('mecanico'))
        ->get(route('diagnosticos.create', $orden))
        ->assertOk()
        ->assertSee('name="envio_uuid"', false)
        ->assertSee('Volver al expediente')
        ->assertSee('diagnosis-completed-alert', false)
        ->assertSee('Comprobando si este formulario ya fue procesado...', false)
        ->assertSee(route('diagnosticos.create', $orden), false)
        ->assertSee(route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos']), false);
});

test('submission status endpoint reports only a diagnosis from the matching order', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $otraOrden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $mecanico = orderModuleUser('mecanico');
    $uuid = (string) Str::uuid();
    $diagnostico = Diagnostico::query()->create([
        'id_orden' => $orden->id_orden,
        'id_usuario' => $mecanico->id,
        'titulo' => 'Hallazgo guardado',
        'descripcion' => 'Descripción guardada',
        'severidad' => 'alta',
        'recomendacion' => 'Revisión recomendada',
        'envio_uuid' => $uuid,
    ]);

    $this->actingAs($mecanico)
        ->getJson(route('diagnosticos.submission-status', [$orden, $uuid]))
        ->assertOk()
        ->assertJson([
            'processed' => true,
            'diagnosis' => [
                'id' => $diagnostico->id_diagnostico,
                'titulo' => 'Hallazgo guardado',
                'descripcion' => 'Descripción guardada',
                'severidad' => 'alta',
                'recomendacion' => 'Revisión recomendada',
            ],
        ]);

    $this->actingAs($mecanico)
        ->getJson(route('diagnosticos.submission-status', [$otraOrden, $uuid]))
        ->assertOk()
        ->assertExactJson(['processed' => false]);
});

test('submission status endpoint requires authentication and protects recepcionista mutations', function () {
    $orden = OrdenServicio::factory()->create(['estado' => 'en_diagnostico']);
    $uuid = (string) Str::uuid();

    $this->getJson(route('diagnosticos.submission-status', [$orden, $uuid]))
        ->assertUnauthorized();

    $this->actingAs(orderModuleUser('recepcionista'))
        ->getJson(route('diagnosticos.submission-status', [$orden, $uuid]))
        ->assertOk()
        ->assertExactJson(['processed' => false]);

    $this->actingAs(orderModuleUser('recepcionista'))
        ->get(route('diagnosticos.create', $orden))
        ->assertForbidden();
});

function makeOrderReceptionPayload(Cliente $cliente, Vehiculo $vehiculo): array
{
    return [
        'id_cliente' => $cliente->id_cliente,
        'id_vehiculo' => $vehiculo->id_vehiculo,
        'fecha_ingreso' => now()->format('Y-m-d H:i:s'),
        'kilometraje_ingreso' => 120000,
        'descripcion_problema' => 'El motor presenta un ruido al encender.',
        'observaciones' => 'Recepción en el área de servicio.',
    ];
}

function orderModuleUser(string $roleName): User
{
    $role = Role::query()->firstOrCreate(
        ['nombre' => $roleName],
        ['descripcion' => 'Rol para pruebas automatizadas.'],
    );

    return User::factory()->create(['id_rol' => $role->id_rol]);
}
