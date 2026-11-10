<?php

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\PartSearch\SerpApiOfferNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function partSearchUser(string $roleName): User
{
    $role = Role::query()->firstOrCreate(
        ['nombre' => $roleName],
        ['descripcion' => 'Rol para pruebas de autopartes.'],
    );

    return User::factory()->create(['id_rol' => $role->id_rol]);
}

function partSearchOrder(): OrdenServicio
{
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create([
        'id_cliente' => $cliente->id_cliente,
        'marca' => 'Chevrolet',
        'modelo' => 'Sonic',
        'anio' => 2016,
        'version' => '1.6',
    ]);
    $mechanic = partSearchUser('mecanico');

    return OrdenServicio::factory()->create([
        'id_vehiculo' => $vehiculo->id_vehiculo,
        'id_usuario' => $mechanic->id,
    ]);
}

test('part search requires a mechanic and is unavailable without an API key', function () {
    config(['services.serpapi.api_key' => null]);
    $order = partSearchOrder();

    foreach (['administrador', 'recepcionista'] as $roleName) {
        $this->actingAs(partSearchUser($roleName));
        $this->getJson(route('autopartes.search', $order).'?q=balatas')->assertForbidden();
    }

    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();

    $this->getJson(route('autopartes.search', $order).'?q=balatas')
        ->assertOk()
        ->assertJson([
            'status' => 'not_configured',
            'query' => 'balatas Chevrolet Sonic 2016 1.6',
        ])
        ->assertJsonCount(0, 'offers');
});

test('SerpApi shopping results are normalized and searched with Mexico parameters', function () {
    config([
        'services.serpapi.api_key' => 'test-key',
        'services.serpapi.location' => 'Mexico City, Mexico',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response([
            'shopping_results' => [
                [
                    'title' => 'Balatas delanteras Sonic',
                    'source' => 'Comercio de prueba',
                    'price' => '$1,250.00 MXN',
                    'currency' => 'MXN',
                    'extracted_price' => 1250,
                    'thumbnail' => 'https://example.com/imagen.jpg',
                    'link' => 'https://example.com/balatas',
                    'brand' => 'Marca de prueba',
                    'part_number' => 'BS-2016',
                    'product_id' => 'SERP-123',
                ],
            ],
        ], 200),
    ]);

    $order = partSearchOrder();
    $this->actingAs(partSearchUser('mecanico'));

    $this->getJson(route('autopartes.search', $order).'?q=balatas delanteras')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('offers.0.title', 'Balatas delanteras Sonic')
        ->assertJsonPath('offers.0.store_name', 'Comercio de prueba')
        ->assertJsonPath('offers.0.price_numeric', 1250)
        ->assertJsonPath('offers.0.currency', 'MXN')
        ->assertJsonPath('offers.0.part_number', 'BS-2016');

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return $parameters['engine'] === 'google_shopping'
            && $parameters['api_key'] === 'test-key'
            && $parameters['q'] === 'balatas delanteras Chevrolet Sonic 2016 1.6'
            && $parameters['gl'] === 'mx'
            && $parameters['hl'] === 'es-419'
            && $parameters['location'] === 'Mexico City, Mexico'
            && $parameters['num'] === '20';
    });
});

test('part search reports no results without fake offers', function () {
    config(['services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $this->actingAs(partSearchUser('mecanico'));

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response(['shopping_results' => []], 200),
    ]);
    $this->getJson(route('autopartes.search', $order).'?q=pieza inexistente')
        ->assertOk()
        ->assertJsonPath('status', 'no_results');
});

test('part search reports provider errors without fake offers', function () {
    config(['services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $this->actingAs(partSearchUser('mecanico'));

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response(['error' => 'provider'], 503),
    ]);
    $this->getJson(route('autopartes.search', $order).'?q=filtro')
        ->assertOk()
        ->assertJsonPath('status', 'provider_error')
        ->assertJsonCount(0, 'offers');
});

test('mechanic adds a static MXN offer only to the correct draft quotation', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
        'subtotal' => '100.00',
        'total' => '100.00',
    ]);
    $otherOrder = partSearchOrder();

    $this->actingAs($mechanic);
    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response([
            'shopping_results' => [
                [
                    'title' => 'Balatas delanteras de oferta',
                    'source' => 'Comercio real reportado',
                    'price' => '$1,250.50 MXN',
                    'currency' => 'MXN',
                    'extracted_price' => 1250.50,
                    'thumbnail' => 'https://example.com/balatas.jpg',
                    'link' => 'https://example.com/publicacion',
                    'brand' => 'Marca reportada',
                    'part_number' => 'PN-2016',
                    'product_id' => 'SERP-123',
                ],
            ],
        ], 200),
    ]);

    $search = $this->getJson(route('autopartes.search', $order).'?q=balatas')->assertOk()->json();
    $offer = $search['offers'][0];
    $idempotencyKey = 'same-request-idempotency-key-123';
    $payload = [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => $idempotencyKey,
        'title' => 'Dato manipulado',
        'store_name' => 'Comercio manipulado',
        'price_numeric' => '1.00',
    ];

    $this->postJson(route('autopartes.add', [$order, $quotation]), $payload)->assertOk()
        ->assertJsonPath('message', 'Refacción agregada correctamente a la cotización');

    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'descripcion' => 'Balatas delanteras de oferta',
        'tipo_concepto' => 'pieza',
        'cantidad' => '1.00',
        'precio_unitario' => '1250.50',
        'importe' => '1250.50',
        'moneda' => 'MXN',
        'tienda_origen' => 'Comercio real reportado',
        'marca' => 'Marca reportada',
        'numero_parte' => 'PN-2016',
        'url_origen' => 'https://example.com/publicacion',
        'imagen_url' => 'https://example.com/balatas.jpg',
    ]);
    $quotation->refresh();
    expect((float) $quotation->subtotal)->toBe(1250.50)
        ->and((float) $quotation->total)->toBe(1250.50);

    $searchCacheKey = 'seibi:part-search:'.$mechanic->getKey().':'.$offer['search_id'];
    $cachedSearch = Cache::get($searchCacheKey);
    $cachedSearch['offers'][$offer['offer_id']]['title'] = 'Título externo cambiado';
    $cachedSearch['offers'][$offer['offer_id']]['price_numeric'] = 1.00;
    Cache::put($searchCacheKey, $cachedSearch, now()->addMinutes(30));
    $quotation->refresh();
    expect($quotation->detalles()->firstOrFail()->descripcion)->toBe('Balatas delanteras de oferta')
        ->and((float) $quotation->detalles()->firstOrFail()->precio_unitario)->toBe(1250.50);

    $this->postJson(route('autopartes.add', [$otherOrder, $quotation]), $payload)->assertNotFound();

    $quotation->update(['estado' => 'enviada']);
    $this->postJson(route('autopartes.add', [$order, $quotation]), $payload)->assertForbidden();
});

test('double delivery returns the original detail and deliberate new delivery creates another line', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    $this->actingAs($mechanic);

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response([
            'shopping_results' => [
                [
                    'title' => 'Filtro de aceite',
                    'source' => 'Tienda uno',
                    'price' => '$300 MXN',
                    'currency' => 'MXN',
                    'extracted_price' => 300,
                    'link' => 'https://example.com/filtro',
                ],
            ],
        ], 200),
    ]);
    $search = $this->getJson(route('autopartes.search', $order).'?q=filtro')->json();
    $offer = $search['offers'][0];

    $payload = [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'same-delivery-key-123456',
    ];
    $first = $this->postJson(route('autopartes.add', [$order, $quotation]), $payload)->assertOk()->json();
    $duplicate = $this->postJson(route('autopartes.add', [$order, $quotation]), $payload)->assertOk()->json();

    expect($first['detail_id'])->toBe($duplicate['detail_id'])
        ->and($duplicate['idempotent'])->toBeTrue()
        ->and($quotation->detalles()->count())->toBe(1);

    $payload['idempotency_key'] = 'deliberate-new-line-123456';
    $this->postJson(route('autopartes.add', [$order, $quotation]), $payload)->assertOk();
    expect($quotation->detalles()->count())->toBe(2);
});

test('administrators and receptionists cannot add offers to drafts', function () {
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    $payload = [
        'title' => 'Refacción',
        'price_numeric' => '100.00',
        'currency' => 'MXN',
    ];

    foreach (['administrador', 'recepcionista'] as $roleName) {
        $this->actingAs(partSearchUser($roleName));
        $this->post(route('autopartes.add', [$order, $quotation]), $payload)->assertForbidden();
    }
});

test('offer without a numeric MXN price cannot be persisted', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    $this->actingAs($mechanic);

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response([
            'shopping_results' => [
                [
                    'title' => 'Refacción sin precio interpretado',
                    'source' => 'Comercio de prueba',
                    'price' => 'Consultar',
                ],
            ],
        ], 200),
    ]);
    $search = $this->getJson(route('autopartes.search', $order).'?q=refaccion')->json();
    $offer = $search['offers'][0];

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'invalid-price-request-12345',
    ])->assertUnprocessable()
        ->assertJsonFragment([
            'message' => 'La oferta no tiene un precio numérico MXN confiable. Captura la pieza manualmente.',
        ]);

    expect($quotation->detalles()->count())->toBe(0);
});

test('general search preserves the original term while compatible search adds vehicle context', function () {
    config(['services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $this->actingAs(partSearchUser('mecanico'));

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response(['shopping_results' => []], 200),
    ]);

    $general = $this->getJson(route('autopartes.search', $order).'?q=Aceite sintético 5W-30&mode=general')
        ->assertOk()
        ->assertJsonPath('mode', 'general')
        ->assertJsonPath('query', 'Aceite sintético 5W-30');

    $compatible = $this->getJson(route('autopartes.search', $order).'?q=Filtro de aceite&mode=compatible')
        ->assertOk()
        ->assertJsonPath('mode', 'compatible')
        ->assertJsonPath('query', 'Filtro de aceite Chevrolet Sonic 2016 1.6');

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return $parameters['q'] === 'Filtro de aceite Chevrolet Sonic 2016 1.6'
            || $parameters['q'] === 'Aceite sintético 5W-30';
    });
});

test('product details enrich cached offers and use cache for repeated consultations', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $this->actingAs($mechanic);

    Http::preventStrayRequests();
    Http::fake(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $parameters);
        if (($parameters['engine'] ?? null) === 'google_product') {
            return Http::response([
                'product_results' => [
                    'brand' => 'Marca enriquecida',
                    'about_the_product' => [
                        'features' => [
                            ['title' => 'Manufacturer Part Number', 'value' => 'MPN-TEST-123'],
                        ],
                    ],
                    'stores' => [
                        ['seller' => 'Comercio real', 'link' => 'https://example.com/producto-real'],
                    ],
                ],
            ], 200);
        }

        return Http::response([
            'shopping_results' => [[
                'title' => 'Producto enriquecible',
                'source' => 'Comercio real',
                'price' => '$800.00 MXN',
                'currency' => 'MXN',
                'extracted_price' => 800,
                'thumbnail' => 'https://example.com/imagen.jpg',
                'product_link' => 'https://google.com/shopping/product/123',
                'product_id' => 'PRODUCT-ENRIQUECIBLE',
            ]],
        ], 200);
    });

    $search = $this->getJson(route('autopartes.search', $order).'?q=filtro')->assertOk()->json();
    $offer = $search['offers'][0];

    $detailsUrl = route('autopartes.details', $order).'?search_id='.$offer['search_id'].'&offer_id='.$offer['offer_id'];
    $this->getJson($detailsUrl)
        ->assertOk()
        ->assertJsonPath('offer.brand', 'Marca enriquecida')
        ->assertJsonPath('offer.part_number', 'MPN-TEST-123')
        ->assertJsonPath('offer.url', 'https://example.com/producto-real');

    $this->getJson($detailsUrl)
        ->assertOk()
        ->assertJsonPath('offer.brand', 'Marca enriquecida');

    $productRequests = collect(Http::recorded())->filter(function (array $recorded) {
        parse_str(parse_url($recorded[0]->url(), PHP_URL_QUERY), $parameters);

        return ($parameters['engine'] ?? null) === 'google_product';
    });
    expect($productRequests->count())->toBe(1);
});

test('shopping normalizer separates commerce image and publication fields', function () {
    $normalizer = app(SerpApiOfferNormalizer::class);

    $offers = $normalizer->normalize([[
        'title' => 'Aceite sintético',
        'source' => 'Comercio de prueba',
        'price' => '$499.00 MXN',
        'currency' => 'MXN',
        'extracted_price' => 499,
        'thumbnail' => 'https://example.com/product.jpg',
        'product_link' => 'https://example.com/oferta',
        'product_id' => 'PRODUCT-123',
        'brand' => null,
        'mpn' => 'OIL-5W30',
    ]]);

    expect($offers[0]['store_name'])->toBe('Comercio de prueba')
        ->and($offers[0]['brand'])->toBeNull()
        ->and($offers[0]['part_number'])->toBe('OIL-5W30')
        ->and($offers[0]['image_url'])->toBe('https://example.com/product.jpg')
        ->and($offers[0]['url'])->toBe('https://example.com/oferta');
});

test('new quotation automatically initializes an editable draft with the part search available', function () {
    $mechanic = partSearchUser('mecanico');
    $order = partSearchOrder();

    $this->actingAs($mechanic);
    $createResponse = $this->get(route('cotizaciones.create', $order));

    $createResponse->assertRedirect();
    $quotation = $order->cotizaciones()->firstOrFail();
    expect($quotation->estado)->toBe('borrador');

    $editResponse = $this->get(route('cotizaciones.edit', [$order, $quotation]))
        ->assertOk()
        ->assertSee('Buscar refacción')
        ->assertDontSee('placeholder="Marca (opcional)"')
        ->assertDontSee('Marca no proporcionada por el comercio')
        ->assertDontSee('Número de parte no disponible');
});

test('show view displays buy button only when product has a valid publication url', function () {
    $mechanic = partSearchUser('mecanico');
    $order = partSearchOrder();
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);

    // Detalle con URL de publicación válida
    $quotation->detalles()->create([
        'tipo_concepto' => 'pieza',
        'descripcion' => 'Balatas con enlace',
        'cantidad' => '1.00',
        'precio_unitario' => '500.00',
        'importe' => '500.00',
        'moneda' => 'MXN',
        'tienda_origen' => 'Tienda de prueba',
        'marca' => 'Marca OK',
        'numero_parte' => 'PN-001',
        'url_origen' => 'https://example.com/comprar-balatas',
        'imagen_url' => 'https://example.com/imagen-balatas.jpg',
    ]);

    // Detalle sin URL de publicación
    $quotation->detalles()->create([
        'tipo_concepto' => 'pieza',
        'descripcion' => 'Filtro sin enlace',
        'cantidad' => '1.00',
        'precio_unitario' => '200.00',
        'importe' => '200.00',
        'moneda' => 'MXN',
        'url_origen' => null,
        'imagen_url' => 'https://example.com/imagen-filtro.jpg',
    ]);

    $this->actingAs($mechanic);
    $response = $this->get(route('cotizaciones.show', [$order, $quotation]))->assertOk();

    // El botón "Comprar" debe aparecer para el producto con URL válida
    $response->assertSee('https://example.com/comprar-balatas');
    $response->assertSee('Comprar');

    // La URL de la imagen NO debe confundirse con la URL de compra
    $response->assertDontSee('https://example.com/imagen-balatas.jpg', false);

    // El texto de "no disponible" para el producto sin enlace
    $response->assertSee('Enlace de compra no disponible');
});

test('offer with url stores publication link separately from image url', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    $this->actingAs($mechanic);

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response([
            'shopping_results' => [[
                'title' => 'Balatas con URL de publicación',
                'source' => 'Tienda con enlace',
                'price' => '$900.00 MXN',
                'currency' => 'MXN',
                'extracted_price' => 900,
                'thumbnail' => 'https://example.com/solo-imagen.jpg',
                'product_link' => 'https://example.com/publicacion-real',
                'brand' => 'Marca Real',
                'part_number' => 'BRK-001',
            ]],
        ], 200),
    ]);

    $search = $this->getJson(route('autopartes.search', $order).'?q=balatas')->assertOk()->json();
    $offer = $search['offers'][0];

    // La URL de publicación y la imagen son campos distintos en el offer normalizado
    expect($offer['url'])->toBe('https://example.com/publicacion-real')
        ->and($offer['image_url'])->toBe('https://example.com/solo-imagen.jpg')
        ->and($offer['url'])->not->toBe($offer['image_url']);

    // Agregar el offer a la cotización
    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'url-test-idempotency-key-456',
    ])->assertOk();

    // Verificar que se guardaron correctamente los campos distintos en BD
    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'descripcion' => 'Balatas con URL de publicación',
        'url_origen' => 'https://example.com/publicacion-real',
        'imagen_url' => 'https://example.com/solo-imagen.jpg',
        'marca' => 'Marca Real',
        'numero_parte' => 'BRK-001',
        'tienda_origen' => 'Tienda con enlace',
    ]);
});

test('offer without url stores null publication link without inventing one', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    $this->actingAs($mechanic);

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response([
            'shopping_results' => [[
                'title' => 'Producto sin enlace de compra',
                'source' => 'Tienda sin link',
                'price' => '$600.00 MXN',
                'currency' => 'MXN',
                'extracted_price' => 600,
                'thumbnail' => 'https://example.com/solo-miniatura.jpg',
                // No hay product_link ni link
            ]],
        ], 200),
    ]);

    $search = $this->getJson(route('autopartes.search', $order).'?q=producto')->assertOk()->json();
    $offer = $search['offers'][0];

    // El offer no debe tener URL de publicación
    expect($offer['url'])->toBeNull()
        ->and($offer['image_url'])->toBe('https://example.com/solo-miniatura.jpg');

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'no-url-idempotency-key-789',
    ])->assertOk();

    // La URL de publicación debe ser null, no inventada
    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'descripcion' => 'Producto sin enlace de compra',
        'url_origen' => null,
        'imagen_url' => 'https://example.com/solo-miniatura.jpg',
    ]);
});

test('offer without brand or part number stores null values without inventing them', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    $this->actingAs($mechanic);

    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => Http::response([
            'shopping_results' => [[
                'title' => 'Refacción genérica sin marca',
                'source' => 'Comercio genérico',
                'price' => '$350.00 MXN',
                'currency' => 'MXN',
                'extracted_price' => 350,
                // Sin brand, sin part_number, sin mpn
            ]],
        ], 200),
    ]);

    $search = $this->getJson(route('autopartes.search', $order).'?q=refaccion')->assertOk()->json();
    $offer = $search['offers'][0];

    // No debe haber brand ni part_number inventados
    expect($offer['brand'])->toBeNull()
        ->and($offer['part_number'])->toBeNull();

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'no-brand-idempotency-key-012',
    ])->assertOk();

    // La marca y número de parte deben ser null en BD
    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'descripcion' => 'Refacción genérica sin marca',
        'marca' => null,
        'numero_parte' => null,
    ]);
});

test('source field is stored as store name not as brand', function () {
    $normalizer = app(SerpApiOfferNormalizer::class);

    $offers = $normalizer->normalize([[
        'title' => 'Balatas originales',
        'source' => 'MercadoLibre',   // source = comercio, NO fabricante
        'price' => '$800.00 MXN',
        'currency' => 'MXN',
        'extracted_price' => 800,
        // Sin brand explícito
    ]]);

    // source va a store_name, no a brand
    expect($offers[0]['store_name'])->toBe('MercadoLibre')
        ->and($offers[0]['brand'])->toBeNull();
});
