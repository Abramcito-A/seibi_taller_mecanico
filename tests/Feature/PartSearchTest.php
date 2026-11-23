<?php

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
use App\Models\Diagnostico;
use App\Models\OrdenServicio;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\PartSearch\PartSearchService;
use App\Services\PartSearch\SerpApiOfferNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

    $order = OrdenServicio::factory()->create([
        'id_vehiculo' => $vehiculo->id_vehiculo,
        'id_usuario' => $mechanic->id,
    ]);

    // Cotizar requiere al menos un hallazgo de diagnóstico registrado.
    Diagnostico::query()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'titulo' => 'Hallazgo de prueba',
        'descripcion' => 'Diagnóstico registrado para poder cotizar.',
        'severidad' => 'media',
    ]);

    return $order;
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
                        ['name' => 'Comercio real', 'link' => 'https://example.com/producto-real', 'extracted_total' => 800],
                    ],
                ],
            ], 200);
        }

        return Http::response([
            'shopping_results' => [[
                'title' => 'Producto enriquecible',
                'source' => 'Comercio real',
                'price' => 'MXN 800.00 ahora',
                'extracted_price' => 800,
                'thumbnail' => 'https://example.com/imagen.jpg',
                'product_link' => 'https://www.google.com/search?ibp=oshop&prds=catalogid:123',
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

test('shopping normalizer keeps google shopping link apart from the direct merchant link', function () {
    $normalizer = app(SerpApiOfferNormalizer::class);

    $offers = $normalizer->normalize([[
        'title' => 'Aceite sintético',
        'source' => 'Comercio de prueba',
        'price' => 'MXN 499.00 ahora',
        'extracted_price' => 499,
        'thumbnail' => 'https://encrypted-tbn1.gstatic.com/shopping?q=tbn:producto',
        'product_link' => 'https://www.google.com/search?ibp=oshop&q=aceite sintetico&prds=catalogid:123',
        'product_id' => 'PRODUCT-123',
        'immersive_product_page_token' => 'TOKEN-123',
        'serpapi_product_api' => 'https://serpapi.com/search.json?engine=google_product&page_token=TOKEN-123',
        'mpn' => 'OIL-5W30',
    ]]);

    expect($offers[0])->toMatchArray([
        'provider' => 'serpapi',
        'provider_engine' => 'google_shopping',
        'provider_product_id' => 'PRODUCT-123',
        'page_token' => 'TOKEN-123',
        'store_name' => 'Comercio de prueba',
        'brand' => null,
        'part_number' => 'OIL-5W30',
        'currency' => 'MXN',
        'image_url' => 'https://encrypted-tbn1.gstatic.com/shopping?q=tbn:producto',
        'shopping_url' => 'https://www.google.com/search?ibp=oshop&q=aceite%20sintetico&prds=catalogid:123',
        'url' => null,
    ]);
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
        'url_shopping' => 'https://www.google.com/search?ibp=oshop&prds=catalogid:999',
        'imagen_url' => 'https://example.com/imagen-filtro.jpg',
    ]);

    $this->actingAs($mechanic);
    $response = $this->get(route('cotizaciones.show', [$order, $quotation]))->assertOk();

    // El botón "Comprar" debe aparecer para el producto con URL válida
    $response->assertSee('https://example.com/comprar-balatas');
    $response->assertSee('Comprar');

    // Comprar abre exactamente la publicación del comercio; la imagen se muestra, pero nunca como enlace
    $response->assertSee('<a href="https://example.com/comprar-balatas" target="_blank" rel="noopener noreferrer nofollow"', false);
    $response->assertSee('src="https://example.com/imagen-balatas.jpg"', false);
    $response->assertDontSee('href="https://example.com/imagen-balatas.jpg"', false);

    // El texto de "no disponible" para el producto sin enlace
    $response->assertSee('Enlace de compra no disponible');

    // La ficha de Google Shopping se ofrece como enlace separado, no como botón Comprar
    $response->assertSeeInOrder(['Enlace de compra no disponible', 'https://www.google.com/search?ibp=oshop&amp;prds=catalogid:999', 'Ver en Google Shopping'], false);
    expect(substr_count($response->getContent(), '>Comprar</a>'))->toBe(1);
});

test('direct merchant link in the search result is stored without a google product request', function () {
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
                'link' => 'https://example.com/publicacion-real',
                'product_id' => 'PRODUCT-CON-ENLACE',
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
    Http::assertNotSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return ($parameters['engine'] ?? null) === 'google_product';
    });
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

/**
 * Resultado de google_shopping con las rutas reales observadas en SerpApi (sin link, brand ni currency).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function realShoppingResult(array $overrides = []): array
{
    return array_merge([
        'position' => 1,
        'title' => 'Wagner QuickStop ZD1522 Juego de pastillas de freno de disco delantero',
        'product_id' => '16044627091158776088',
        'product_link' => 'https://www.google.com/search?ibp=oshop&q=balatas&prds=catalogid:16044627091158776088',
        'immersive_product_page_token' => 'TOKEN-OFERTA-SELECCIONADA',
        'serpapi_product_api' => 'https://serpapi.com/search.json?engine=google_product&page_token=TOKEN-OFERTA-SELECCIONADA',
        'serpapi_immersive_product_api' => 'https://serpapi.com/search.json?engine=google_immersive_product&page_token=TOKEN-OFERTA-SELECCIONADA',
        'source' => 'Mercado Libre',
        'multiple_sources' => true,
        'price' => 'MXN 932.00 ahora',
        'extracted_price' => 932,
        'installment' => ['price' => 'MXN 56.32/mes', 'extracted_price' => 56.32, 'period' => 24],
        'thumbnail' => 'https://encrypted-tbn1.gstatic.com/shopping?q=tbn:balatas',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $shoppingResult
 */
function fakeSerpApiEngines(array $shoppingResult, Closure $productResponse): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json*' => function ($request) use ($shoppingResult, $productResponse) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $parameters);

            return ($parameters['engine'] ?? null) === 'google_product'
                ? $productResponse()
                : Http::response(['shopping_results' => [$shoppingResult]], 200);
        },
    ]);
}

/**
 * @return array{0: OrdenServicio, 1: Cotizacion}
 */
function partSearchDraft(): array
{
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $quotation = Cotizacion::factory()->create([
        'id_orden' => $order->id_orden,
        'id_usuario' => $mechanic->id,
        'estado' => 'borrador',
    ]);
    test()->actingAs($mechanic);

    return [$order, $quotation];
}

test('selected offer stores the direct merchant link from google product requested by page token', function () {
    [$order, $quotation] = partSearchDraft();
    fakeSerpApiEngines(realShoppingResult(), fn () => Http::response(['product_results' => [
        'title' => 'Wagner QuickStop ZD1522',
        'brand' => 'Wagner Tuning',
        'stores' => [
            ['position' => 1, 'name' => 'Amazon', 'link' => 'https://www.amazon.com.mx/dp/B00FZSH7XA', 'price' => 'MXN 662.57', 'extracted_price' => 662.57, 'total' => 'MXN 662.57', 'extracted_total' => 662.57],
            ['position' => 2, 'name' => 'Mercado Libre', 'link' => 'https://www.mercadolibre.com.mx/balatas-wagner-zd1522/up/MLMU3798581', 'price' => 'MXN 56.32', 'extracted_price' => 56.32, 'total' => 'MXN 932.00', 'extracted_total' => 932],
        ],
        'about_the_product' => ['features' => [
            ['title' => 'Dimensiones del producto', 'value' => '8,5 x 5,25 x 2,5 pulgadas'],
            ['title' => 'País de origen', 'value' => 'EE. UU.'],
        ]],
    ]], 200));
    $offer = $this->getJson(route('autopartes.search', $order).'?q=balatas')->assertOk()->json('offers.0');

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'page-token-direct-link-123',
    ])->assertOk();

    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'precio_unitario' => '932.00',
        'tienda_origen' => 'Mercado Libre',
        'marca' => 'Wagner Tuning',
        'fabricante' => null,
        'numero_parte' => null,
        'url_origen' => 'https://www.mercadolibre.com.mx/balatas-wagner-zd1522/up/MLMU3798581',
        'url_shopping' => 'https://www.google.com/search?ibp=oshop&q=balatas&prds=catalogid:16044627091158776088',
        'imagen_url' => 'https://encrypted-tbn1.gstatic.com/shopping?q=tbn:balatas',
        'proveedor' => 'serpapi',
        'proveedor_motor' => 'google_shopping+google_product',
        'proveedor_producto_id' => '16044627091158776088',
    ]);
    expect($quotation->detalles()->firstOrFail()->detalles_tecnicos)->toBe(['features' => [
        ['title' => 'Dimensiones del producto', 'value' => '8,5 x 5,25 x 2,5 pulgadas'],
        ['title' => 'País de origen', 'value' => 'EE. UU.'],
    ]]);
    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return ($parameters['engine'] ?? null) === 'google_product'
            && ($parameters['page_token'] ?? null) === 'TOKEN-OFERTA-SELECCIONADA'
            && ! array_key_exists('product_id', $parameters);
    });
});

test('no direct link is stored when google product does not contain exactly the selected seller and price', function (array $stores) {
    [$order, $quotation] = partSearchDraft();
    fakeSerpApiEngines(realShoppingResult(), fn () => Http::response(['product_results' => [
        'brand' => 'Wagner Tuning',
        'stores' => $stores,
    ]], 200));
    $offer = $this->getJson(route('autopartes.search', $order).'?q=balatas')->assertOk()->json('offers.0');

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'no-matching-store-key-123',
    ])->assertOk();

    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'tienda_origen' => 'Mercado Libre',
        'url_origen' => null,
        'url_shopping' => 'https://www.google.com/search?ibp=oshop&q=balatas&prds=catalogid:16044627091158776088',
        'imagen_url' => 'https://encrypted-tbn1.gstatic.com/shopping?q=tbn:balatas',
        'detalles_tecnicos' => null,
    ]);
})->with([
    'otro comercio con el mismo precio' => [[
        ['name' => 'Amazon', 'link' => 'https://www.amazon.com.mx/dp/B00FZSH7XA', 'extracted_price' => 932, 'extracted_total' => 932],
    ]],
    'mismo comercio con otro precio' => [[
        ['name' => 'Mercado Libre', 'link' => 'https://www.mercadolibre.com.mx/otra-publicacion', 'extracted_price' => 700, 'extracted_total' => 700],
    ]],
    'mismo comercio y precio en dos publicaciones' => [[
        ['name' => 'Mercado Libre', 'link' => 'https://www.mercadolibre.com.mx/publicacion-a', 'extracted_total' => 932],
        ['name' => 'Mercado Libre', 'link' => 'https://www.mercadolibre.com.mx/publicacion-b', 'extracted_total' => 932],
    ]],
    'enlace de la tienda apunta a Google' => [[
        ['name' => 'Mercado Libre', 'link' => 'https://www.google.com/aclk?sa=l&ai=redirect', 'extracted_total' => 932],
    ]],
    'sin tiendas' => [[]],
]);

test('manufacturer and part number are stored only when the provider attributes report them', function () {
    [$order, $quotation] = partSearchDraft();
    fakeSerpApiEngines(realShoppingResult([
        'title' => 'Filtro de aceite fram extra guard ph3614',
        'source' => 'Team Mototec',
        'price' => 'MXN 130.00',
        'extracted_price' => 130,
    ]), fn () => Http::response(['product_results' => [
        'stores' => [['name' => 'Team Mototec', 'link' => 'https://teammototec.com/producto/filtro-de-aceite-fram-ph3614/', 'extracted_price' => 130, 'extracted_total' => 130]],
        'product_attributes' => [
            ['name' => 'marca', 'value' => 'FRAM'],
            ['name' => 'Fabricante', 'value' => 'FRAM Group'],
            ['name' => 'Número de parte', 'value' => 'PH3614'],
        ],
        'about_the_product' => ['features' => [['title' => 'Marca', 'value' => 'FRAM']]],
    ]], 200));
    $offer = $this->getJson(route('autopartes.search', $order).'?q=filtro')->assertOk()->json('offers.0');

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'attributes-part-number-123',
    ])->assertOk();

    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'tienda_origen' => 'Team Mototec',
        'marca' => 'FRAM',
        'fabricante' => 'FRAM Group',
        'numero_parte' => 'PH3614',
        'url_origen' => 'https://teammototec.com/producto/filtro-de-aceite-fram-ph3614/',
        'detalles_tecnicos' => null,
    ]);
});

test('google product failure still adds the part without a direct link and is retried on the next consultation', function () {
    Sleep::fake();
    [$order, $quotation] = partSearchDraft();
    $productAvailable = false;
    fakeSerpApiEngines(realShoppingResult(), function () use (&$productAvailable) {
        return $productAvailable
            ? Http::response(['product_results' => ['stores' => [
                ['name' => 'Mercado Libre', 'link' => 'https://www.mercadolibre.com.mx/balatas-wagner-zd1522/up/MLMU3798581', 'extracted_total' => 932],
            ]]], 200)
            : Http::response(['error' => 'Servicio no disponible'], 503);
    });
    $offer = $this->getJson(route('autopartes.search', $order).'?q=balatas')->assertOk()->json('offers.0');

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'provider-down-key-123456',
    ])->assertOk();
    $productAvailable = true;
    $details = $this->getJson(route('autopartes.details', $order).'?search_id='.$offer['search_id'].'&offer_id='.$offer['offer_id']);

    $this->assertDatabaseHas('detalles_cotizacion', [
        'id_cotizacion' => $quotation->id_cotizacion,
        'descripcion' => 'Wagner QuickStop ZD1522 Juego de pastillas de freno de disco delantero',
        'url_origen' => null,
        'imagen_url' => 'https://encrypted-tbn1.gstatic.com/shopping?q=tbn:balatas',
    ]);
    $details->assertOk()
        ->assertJsonPath('offer.url', 'https://www.mercadolibre.com.mx/balatas-wagner-zd1522/up/MLMU3798581')
        ->assertJsonPath('offer.details_status', 'enriched');
});

test('editing a draft keeps the provider snapshot of a searched part', function () {
    [$order, $quotation] = partSearchDraft();
    $quotation->detalles()->create([
        'tipo_concepto' => 'pieza',
        'descripcion' => 'Balatas Wagner ZD1522',
        'cantidad' => '1.00',
        'precio_unitario' => '932.00',
        'importe' => '932.00',
        'moneda' => 'MXN',
        'tienda_origen' => 'Mercado Libre',
        'marca' => 'Wagner Tuning',
        'fabricante' => 'Wagner Brake',
        'url_origen' => 'https://www.mercadolibre.com.mx/balatas-wagner-zd1522/up/MLMU3798581',
        'url_shopping' => 'https://www.google.com/search?ibp=oshop&prds=catalogid:16044627091158776088',
        'imagen_url' => 'https://encrypted-tbn1.gstatic.com/shopping?q=tbn:balatas',
        'proveedor' => 'serpapi',
        'proveedor_motor' => 'google_shopping+google_product',
        'proveedor_producto_id' => '16044627091158776088',
        'detalles_tecnicos' => ['features' => [['title' => 'País de origen', 'value' => 'EE. UU.']]],
    ]);
    $editPage = $this->get(route('cotizaciones.edit', [$order, $quotation]))->assertOk();
    preg_match_all('/name="(detalles\[0\]\[[a-z_]+\])" value="([^"]*)"/', $editPage->getContent(), $inputs, PREG_SET_ORDER);
    $formDetail = [];
    foreach ($inputs as [, $name, $value]) {
        $formDetail[substr($name, 12, -1)] = html_entity_decode($value, ENT_QUOTES);
    }

    $this->put(route('cotizaciones.update', [$order, $quotation]), [
        'titulo' => 'Cambio de balatas',
        'tipo' => 'mantenimiento',
        'detalles' => [$formDetail + ['tipo_concepto' => 'pieza']],
    ])->assertRedirect();

    $detail = $quotation->detalles()->firstOrFail();
    expect($detail->only(['fabricante', 'url_origen', 'url_shopping', 'proveedor', 'proveedor_motor', 'proveedor_producto_id', 'detalles_tecnicos']))->toBe([
        'fabricante' => 'Wagner Brake',
        'url_origen' => 'https://www.mercadolibre.com.mx/balatas-wagner-zd1522/up/MLMU3798581',
        'url_shopping' => 'https://www.google.com/search?ibp=oshop&prds=catalogid:16044627091158776088',
        'proveedor' => 'serpapi',
        'proveedor_motor' => 'google_shopping+google_product',
        'proveedor_producto_id' => '16044627091158776088',
        'detalles_tecnicos' => ['features' => [['title' => 'País de origen', 'value' => 'EE. UU.']]],
    ]);
});

/**
 * Resultado de google_shopping con la forma real observada en la muestra de 2026-11-16 (sin enlace directo).
 */
function sampledShoppingResult(string $title, string $source = 'Mercado Libre', float $price = 499.0): array
{
    return [
        'title' => $title,
        'source' => $source,
        'price' => 'MXN '.number_format($price, 2, '.', ''),
        'extracted_price' => $price,
        'product_id' => (string) crc32($title),
        'product_link' => 'https://www.google.com/search?ibp=oshop&q='.rawurlencode($title).'&prds=productid:'.crc32($title),
        'immersive_product_page_token' => 'token-'.crc32($title),
        'thumbnail' => 'https://encrypted-tbn1.gstatic.com/shopping?q=tbn:'.crc32($title),
    ];
}

function toyotaCorollaOrder(string $version = 'Trim: LE'): OrdenServicio
{
    $cliente = Cliente::factory()->create();
    $vehiculo = Vehiculo::factory()->create([
        'id_cliente' => $cliente->id_cliente,
        'marca' => 'Toyota',
        'modelo' => 'Corolla',
        'anio' => 2022,
        'version' => $version,
    ]);

    return OrdenServicio::factory()->create([
        'id_vehiculo' => $vehiculo->id_vehiculo,
        'id_usuario' => partSearchUser('mecanico')->id,
    ]);
}

test('compatible part search keeps automotive offers, drops foreign products and never confirms compatibility', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::response(['shopping_results' => [
        sampledShoppingResult('Balatas Delanteras Toyota Corolla 2019-2021 Ceramicas Grc'),
        sampledShoppingResult('Piezas de cortadora de césped Bujías de iridio 93501 compatibles con Bujía', 'Amazon'),
        sampledShoppingResult('DYNAMIK Balatas Cerámicas Delanteras Toyota Corolla 2020-2022 Corolla L4'),
        sampledShoppingResult('Balata delantera MASTERFIT MFD-2266'),
        sampledShoppingResult('Kit Amortiguadores Delanteros Nissan Versa 2020 - 2025 Originales'),
    ]], 200)]);

    $response = $this->getJson(route('autopartes.search', $order).'?q=balatas delanteras&mode=compatible')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('query', 'balatas delanteras Toyota Corolla 2022')
        ->assertJsonPath('summary', ['received' => 5, 'automotive' => 4, 'uncertain' => 0, 'excluded' => 1]);

    $offers = collect($response->json('offers'));
    expect($offers->pluck('title')->all())->toBe([
        'DYNAMIK Balatas Cerámicas Delanteras Toyota Corolla 2020-2022 Corolla L4',
        'Balata delantera MASTERFIT MFD-2266',
        'Balatas Delanteras Toyota Corolla 2019-2021 Ceramicas Grc',
        'Kit Amortiguadores Delanteros Nissan Versa 2020 - 2025 Originales',
    ])
        ->and($offers->pluck('compatibility.status')->all())->toBe(['mentioned', 'unknown', 'conflict', 'conflict'])
        ->and($offers->pluck('compatibility.confirmed')->unique()->all())->toBe([false])
        ->and($offers[2]['compatibility']['label'])->toContain('no incluyen 2022')
        ->and($offers[3]['compatibility']['label'])->toContain('otra marca (nissan)');
});

test('general consumable search keeps the mechanic term and does not add vehicle data', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::response(['shopping_results' => [
        sampledShoppingResult('Anticongelante Bardahl Climatizado', 'Bodega Aurrera', 189.0),
        sampledShoppingResult('Gonher Anticongelante OAT 35% Azul 3.78L hecho en México', 'Amazon MX', 210.0),
    ]], 200)]);

    $this->getJson(route('autopartes.search', $order).'?q=anticongelante&mode=general')
        ->assertOk()
        ->assertJsonPath('query', 'anticongelante')
        ->assertJsonPath('summary.automotive', 2)
        ->assertJsonPath('offers.0.compatibility', null);

    Http::assertSentCount(1);
});

test('ambiguous general term is narrowed to the automotive domain and foreign results are discarded', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::response(['shopping_results' => [
        sampledShoppingResult('Aceite de Oliva Great Value Extra Virgen', 'Walmart.com.mx', 120.0),
        sampledShoppingResult('Aceite Quaker State Sintético 5W30 para Motor Gasolina Máxima Protección', 'Walmart.com.mx', 310.0),
        sampledShoppingResult('Aceite Vegetal Cristal 5 L', 'City Club', 150.0),
    ]], 200)]);

    $this->getJson(route('autopartes.search', $order).'?q=aceite&mode=general')
        ->assertOk()
        ->assertJsonPath('query', 'aceite automotriz')
        ->assertJsonPath('summary', ['received' => 3, 'automotive' => 1, 'uncertain' => 0, 'excluded' => 2])
        ->assertJsonCount(1, 'offers')
        ->assertJsonPath('offers.0.title', 'Aceite Quaker State Sintético 5W30 para Motor Gasolina Máxima Protección');
});

test('clearly non automotive search is rejected without consulting the provider', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake();

    $this->getJson(route('autopartes.search', $order).'?q=audífonos bluetooth&mode=general')
        ->assertOk()
        ->assertJsonPath('status', 'not_automotive')
        ->assertJsonPath('offers', [])
        ->assertJsonPath('message', 'La búsqueda "audífonos bluetooth" no corresponde a refacciones ni productos automotrices, por lo que no se consultó al proveedor. Puedes capturar el concepto manualmente.');

    Http::assertNothingSent();
});

test('provider results that are all foreign to mechanics report no relevant results', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::response(['shopping_results' => [
        sampledShoppingResult('Vibrador Suave De Masaje Vibratorio Portátil Para Mujer', 'eBay - zecico', 204.53),
    ]], 200)]);

    $this->getJson(route('autopartes.search', $order).'?q=balatas&mode=compatible')
        ->assertOk()
        ->assertJsonPath('status', 'no_relevant_results')
        ->assertJsonPath('summary.excluded', 1)
        ->assertJsonPath('offers', []);
});

test('compatible search relaxes year and version only when the full vehicle query returns nothing', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::sequence()
        ->push(['error' => "Google hasn't returned any results for this query."], 200)
        ->push(['shopping_results' => [sampledShoppingResult('Toyota - Piezas originales - Rotor, freno Fr (43512-02240)')]], 200),
    ]);

    $this->getJson(route('autopartes.search', $order).'?q=discos de freno&mode=compatible')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('attempted_queries', ['discos de freno Toyota Corolla 2022', 'discos de freno Toyota Corolla'])
        ->assertJsonPath('query', 'discos de freno Toyota Corolla');

    Http::assertSentCount(2);
});

test('adding an offer while editing returns the persisted row and the new revision without reloading', function () {
    [$order, $quotation] = partSearchDraft();
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::response(['shopping_results' => [
        sampledShoppingResult('Filtro de Aceite Gonher GP3675M', 'Refaccionaria Anabeli', 57.0) + ['link' => 'https://anabeli.mx/products/filtro-gp3675m'],
    ]], 200)]);
    $offer = $this->getJson(route('autopartes.search', $order).'?q=filtro de aceite')->json('offers.0');

    $response = $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'inline-row-idempotency-key-001',
    ])->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('total', '57.00');

    $detailId = $response->json('detail_id');
    expect($response->json('row_html'))
        ->toContain('name="detalles[__INDEX__][id_detalle]" value="'.$detailId.'"')
        ->toContain('value="Filtro de Aceite Gonher GP3675M"')
        ->not->toContain('[url_shopping]');
});

test('identical searches reuse the cached provider response and usage is counted per engine', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::response(['shopping_results' => [
        sampledShoppingResult('Anticongelante Bardahl Climatizado', 'Bodega Aurrera', 189.0),
    ]], 200)]);

    $first = $this->getJson(route('autopartes.search', $order).'?q=anticongelante&mode=general')->assertOk()->json();
    $second = $this->getJson(route('autopartes.search', $order).'?q=anticongelante&mode=general')->assertOk()->json();

    Http::assertSentCount(1);
    expect($second['offers'][0]['title'])->toBe('Anticongelante Bardahl Climatizado')
        ->and($second['offers'][0]['offer_id'])->not->toBe($first['offers'][0]['offer_id'])
        ->and(app(PartSearchService::class)->usageFor(now()->toDateString())['google_shopping'])
        ->toBe(['provider' => 1, 'cache' => 1]);
});

test('added offer records the original provider price and why it has no direct link', function () {
    [$order, $quotation] = partSearchDraft();
    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json?*engine=google_shopping*' => Http::response(['shopping_results' => [
            sampledShoppingResult('Balatas Delanteras Toyota Corolla 2019-2021 Ceramicas Grc', 'Mercado Libre', 600.0),
        ]], 200),
        'https://serpapi.com/search.json?*engine=google_product*' => Http::response(['product_results' => ['stores' => [
            ['name' => 'Otro comercio', 'link' => 'https://otro.example/balatas', 'extracted_price' => 600.0],
        ]]], 200),
    ]);
    $offer = $this->getJson(route('autopartes.search', $order).'?q=balatas')->json('offers.0');

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'link-status-idempotency-key-01',
    ])->assertOk();

    $detail = $quotation->detalles()->firstOrFail();
    expect($detail->url_origen)->toBeNull()
        ->and($detail->precio_proveedor)->toBe('600.00')
        ->and($detail->enlace_estado)->toBe('sin_enlace')
        ->and($detail->enlace_motivo)->toBe('El detalle del producto no muestra exactamente el mismo comercio y precio.')
        ->and($detail->enlace_verificado_en)->not->toBeNull();
});

/**
 * @return array{0: DetalleCotizacion, 1: DetalleCotizacion, 2: DetalleCotizacion, 3: Cotizacion}
 */
function quotationsWithMissingLinks(): array
{
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $mechanic = partSearchUser('mecanico');
    $draft = Cotizacion::factory()->create(['id_orden' => $order->id_orden, 'id_usuario' => $mechanic->id, 'estado' => 'borrador']);
    $sent = Cotizacion::factory()->create(['id_orden' => $order->id_orden, 'id_usuario' => $mechanic->id, 'estado' => 'enviada']);
    $piece = fn (Cotizacion $quotation, array $attributes) => $quotation->detalles()->create($attributes + [
        'tipo_concepto' => 'pieza', 'cantidad' => '1.00', 'moneda' => 'MXN', 'importe' => $attributes['precio_unitario'],
    ]);

    return [
        // El mecánico cambió el precio; la verificación usa el precio original de la oferta.
        $piece($draft, ['descripcion' => 'Bujías NGK', 'tienda_origen' => 'eBay - yes_auto_parts', 'precio_unitario' => '430.50', 'precio_proveedor' => '450.28', 'proveedor_producto_id' => '2431288663493831258']),
        $piece($draft, ['descripcion' => 'Jgo Balatas Pointer', 'tienda_origen' => 'Mercado Libre', 'precio_unitario' => '232.00']),
        $piece($sent, ['descripcion' => 'Pieza en cotización enviada', 'tienda_origen' => 'eBay - yes_auto_parts', 'precio_unitario' => '450.28', 'proveedor_producto_id' => '2431288663493831258']),
        $draft,
    ];
}

test('link recovery preview reports candidates without writing anything', function () {
    [$recoverable, $withoutIdentifier, $inSentQuotation] = quotationsWithMissingLinks();
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json?*engine=google_product*' => Http::response(['product_results' => ['stores' => [
        ['name' => 'eBay - yes_auto_parts', 'link' => 'https://www.ebay.com/itm/111', 'extracted_price' => 450.28],
        ['name' => 'Mercado Libre', 'link' => 'https://www.mercadolibre.com.mx/otra', 'extracted_price' => 450.28],
    ]]], 200)]);

    $this->artisan('seibi:cotizaciones:recuperar-enlaces')
        ->expectsOutputToContain('Recuperables: 1 · Recuperables por tipo de cambio: 0 · No recuperables: 1 · Error del proveedor: 0 · Protegidas (no borrador): 1')
        ->expectsOutputToContain('Vista previa: no se modificó ningún registro.')
        ->assertSuccessful();

    Http::assertSentCount(1);
    foreach ([$recoverable, $withoutIdentifier, $inSentQuotation] as $detail) {
        expect($detail->refresh()->only(['url_origen', 'enlace_estado']))->toBe(['url_origen' => null, 'enlace_estado' => null]);
    }
});

test('link recovery applies only the exact seller and price match and records failures', function () {
    [$recoverable, $withoutIdentifier, $inSentQuotation, $draft] = quotationsWithMissingLinks();
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json?*engine=google_product*' => Http::response(['product_results' => ['stores' => [
        ['name' => 'eBay - yes_auto_parts', 'link' => 'https://www.ebay.com/itm/111', 'extracted_price' => 450.28],
        ['name' => 'Mercado Libre', 'link' => 'https://www.mercadolibre.com.mx/otra', 'extracted_price' => 450.28],
    ]]], 200)]);

    $this->artisan('seibi:cotizaciones:recuperar-enlaces', ['--apply' => true])->assertSuccessful();

    expect($recoverable->refresh()->url_origen)->toBe('https://www.ebay.com/itm/111')
        ->and($recoverable->enlace_estado)->toBe('recuperado')
        ->and($withoutIdentifier->refresh()->url_origen)->toBeNull()
        ->and($withoutIdentifier->enlace_estado)->toBe('no_recuperable')
        ->and($withoutIdentifier->enlace_motivo)->toBe('Sin identificador del producto o sin comercio registrado: no es posible verificar la oferta original.')
        ->and($inSentQuotation->refresh()->url_origen)->toBeNull()
        ->and($inSentQuotation->enlace_estado)->toBeNull()
        ->and($draft->refresh()->revision)->toBe(3);
});

test('link recovery never assigns a link from a different seller or price', function (array $stores) {
    [$recoverable] = quotationsWithMissingLinks();
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json?*engine=google_product*' => Http::response(['product_results' => ['stores' => $stores]], 200)]);

    $this->artisan('seibi:cotizaciones:recuperar-enlaces', ['--apply' => true, '--cotizacion' => [$recoverable->id_cotizacion]])->assertSuccessful();

    expect($recoverable->refresh()->url_origen)->toBeNull()
        ->and($recoverable->enlace_estado)->toBe('no_recuperable');
})->with([
    'same seller with a different price' => [[['name' => 'eBay - yes_auto_parts', 'link' => 'https://www.ebay.com/itm/222', 'extracted_price' => 399.00]]],
    'different seller with the same price' => [[['name' => 'eBay - otro_vendedor', 'link' => 'https://www.ebay.com/itm/333', 'extracted_price' => 450.28]]],
    'two listings of the same seller and price' => [[
        ['name' => 'eBay - yes_auto_parts', 'link' => 'https://www.ebay.com/itm/444', 'extracted_price' => 450.28],
        ['name' => 'eBay - yes_auto_parts', 'link' => 'https://www.ebay.com/itm/555', 'extracted_price' => 450.28],
    ]],
]);

test('link recovery records a product the provider no longer returns instead of retrying forever', function () {
    [$recoverable] = quotationsWithMissingLinks();
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json?*engine=google_product*' => Http::response([
        'error' => "Google Product hasn't returned any results for this query.",
    ], 200)]);

    $this->artisan('seibi:cotizaciones:recuperar-enlaces', ['--apply' => true, '--cotizacion' => [$recoverable->id_cotizacion]])->assertSuccessful();
    $this->artisan('seibi:cotizaciones:recuperar-enlaces', ['--cotizacion' => [$recoverable->id_cotizacion]])->assertSuccessful();

    Http::assertSentCount(1);
    expect($recoverable->refresh()->enlace_estado)->toBe('no_recuperable')
        ->and($recoverable->enlace_motivo)->toBe('El proveedor ya no devuelve resultados para este producto (no se guardó el token de la oferta original).');
});

test('the offer token is stored when adding and preferred when retrying the link', function () {
    [$order, $quotation] = partSearchDraft();
    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json?*engine=google_shopping*' => Http::response(['shopping_results' => [
            sampledShoppingResult('Juego De Pastillas De Freno Cruzadas Para Toyota Corolla 2022-2025 Eje', 'eBay - autopartsgeek2', 766.74),
        ]], 200),
        'https://serpapi.com/search.json?*engine=google_product*' => Http::sequence()
            ->push(['product_results' => ['stores' => []]], 200)
            ->push(['product_results' => ['stores' => [
                ['name' => 'eBay - autopartsgeek2', 'link' => 'https://www.ebay.com/itm/307094247793', 'extracted_price' => 766.74],
            ]]], 200),
    ]);
    $offer = $this->getJson(route('autopartes.search', $order).'?q=balatas')->json('offers.0');
    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'],
        'offer_id' => $offer['offer_id'],
        'idempotency_key' => 'stored-token-idempotency-key-01',
    ])->assertOk();
    $detail = $quotation->detalles()->firstOrFail();
    expect($detail->proveedor_token)->toBe($offer['page_token'])
        ->and($detail->toArray())->not->toHaveKey('proveedor_token');

    // La caché del primer detalle (sin tiendas) expira antes del reintento.
    Cache::flush();
    $this->artisan('seibi:cotizaciones:recuperar-enlaces', ['--apply' => true])->assertSuccessful();

    expect($detail->refresh()->url_origen)->toBe('https://www.ebay.com/itm/307094247793')
        ->and($detail->enlace_estado)->toBe('recuperado');
    Http::assertSent(function ($request) use ($offer): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return ($parameters['engine'] ?? null) === 'google_product'
            && ($parameters['page_token'] ?? null) === $offer['page_token']
            && ! isset($parameters['product_id']);
    });
});

test('an empty provider response is not cached so the next search reaches the provider again', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::sequence()
        ->push(['error' => "Google hasn't returned any results for this query."], 200)
        ->push(['error' => "Google hasn't returned any results for this query."], 200)
        ->push(['shopping_results' => [sampledShoppingResult('Anticongelante Bardahl Climatizado', 'Bodega Aurrera', 189.0)]], 200),
    ]);

    $this->getJson(route('autopartes.search', $order).'?q=anticongelante&mode=general')->assertJsonPath('status', 'no_results');
    $this->getJson(route('autopartes.search', $order).'?q=anticongelante&mode=general')
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('query', 'anticongelante');

    Http::assertSentCount(3);
});

test('a specific general term is qualified with the automotive domain only when the literal search is empty', function () {
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = toyotaCorollaOrder();
    $this->actingAs(partSearchUser('mecanico'));
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json*' => Http::sequence()
        ->push(['error' => "Google hasn't returned any results for this query."], 200)
        ->push(['shopping_results' => [sampledShoppingResult('Anticongelante Prestone 3.78 l', 'Walmart.com.mx', 230.0)]], 200),
    ]);

    $this->getJson(route('autopartes.search', $order).'?q=anticongelante&mode=general')
        ->assertOk()
        ->assertJsonPath('attempted_queries', ['anticongelante', 'anticongelante automotriz'])
        ->assertJsonPath('query', 'anticongelante automotriz')
        ->assertJsonPath('summary.automotive', 1)
        ->assertJsonPath('offers.0.compatibility', null);
});

const CONVERSION_TITLE = 'Juego De Pastillas De Freno Cruzadas Para Toyota Corolla 2022-2025 Eje';

/**
 * Pieza sin enlace cuya oferta original se publicó en USD (forma real observada en eBay).
 */
function detailPublishedInForeignCurrency(array $overrides = []): DetalleCotizacion
{
    config(['cache.default' => 'array', 'services.serpapi.api_key' => 'test-key']);
    $order = partSearchOrder();
    $quotation = Cotizacion::factory()->create(['id_orden' => $order->id_orden, 'id_usuario' => partSearchUser('mecanico')->id, 'estado' => 'borrador']);

    return $quotation->detalles()->create($overrides + [
        'tipo_concepto' => 'pieza', 'descripcion' => 'Balatas editadas por el mecánico', 'cantidad' => '1.00', 'moneda' => 'MXN',
        'precio_unitario' => '800.00', 'importe' => '800.00', 'precio_proveedor' => '766.74',
        'moneda_original' => 'USD', 'precio_moneda_original' => '41.00', 'titulo_proveedor' => CONVERSION_TITLE,
        'tienda_origen' => 'eBay - autopartsgeek2', 'proveedor_producto_id' => '6018551171261469629',
    ]);
}

function convertedStore(array $overrides = []): array
{
    return $overrides + [
        'name' => 'eBay - autopartsgeek2', 'link' => 'https://www.ebay.com/itm/307094247793', 'title' => CONVERSION_TITLE,
        'extracted_price' => 766.11, 'extracted_total' => 766.11, 'alt_currency' => '(USD 41.22)',
    ];
}

function fakeProductStores(array $stores): void
{
    Http::preventStrayRequests();
    Http::fake(['https://serpapi.com/search.json?*engine=google_product*' => Http::response(['product_results' => ['stores' => $stores]], 200)]);
}

test('a currency conversion difference within 1 percent recovers the same publication and keeps the original price', function () {
    $detail = detailPublishedInForeignCurrency();
    fakeProductStores([convertedStore(), convertedStore(['name' => 'Mercado Libre', 'link' => 'https://www.mercadolibre.com.mx/otra', 'alt_currency' => null])]);

    $this->artisan('seibi:cotizaciones:recuperar-enlaces', ['--apply' => true])
        ->expectsOutputToContain('Recuperables por tipo de cambio: 1')
        ->assertSuccessful();

    $detail->refresh();
    expect($detail->url_origen)->toBe('https://www.ebay.com/itm/307094247793')
        ->and($detail->enlace_estado)->toBe('recuperado_conversion')
        ->and($detail->enlace_motivo)->toBe('Misma publicación de "eBay - autopartsgeek2" en USD: USD 41.00 → 41.22; MXN 766.74 → 766.11 (0.08%, tipo de cambio).')
        ->and($detail->only(['precio_unitario', 'precio_proveedor', 'moneda_original', 'precio_moneda_original']))
        ->toBe(['precio_unitario' => '800.00', 'precio_proveedor' => '766.74', 'moneda_original' => 'USD', 'precio_moneda_original' => '41.00']);
});

test('a difference of exactly 1 percent is the tolerance limit', function () {
    $detail = detailPublishedInForeignCurrency(['precio_proveedor' => '1000.00', 'precio_moneda_original' => '50.00']);
    fakeProductStores([convertedStore(['extracted_price' => 1010.00, 'extracted_total' => 1010.00, 'alt_currency' => '(USD 50.50)'])]);

    $this->artisan('seibi:cotizaciones:recuperar-enlaces', ['--apply' => true])->assertSuccessful();

    expect($detail->refresh()->enlace_estado)->toBe('recuperado_conversion');
});

test('the currency tolerance is not applied without complete evidence', function (array $detailOverrides, array $stores) {
    $detail = detailPublishedInForeignCurrency($detailOverrides);
    fakeProductStores($stores);

    $this->artisan('seibi:cotizaciones:recuperar-enlaces', ['--apply' => true])->assertSuccessful();

    expect($detail->refresh()->url_origen)->toBeNull()
        ->and($detail->enlace_estado)->toBe('no_recuperable');
})->with([
    'more than 1 percent in pesos' => [['precio_proveedor' => '1000.00', 'precio_moneda_original' => '50.00'], [convertedStore(['extracted_price' => 1010.01, 'extracted_total' => 1010.01, 'alt_currency' => '(USD 50.00)'])]],
    'listing price changed in its own currency' => [[], [convertedStore(['extracted_price' => 770.00, 'alt_currency' => '(USD 42.00)'])]],
    'different merchant with the same publication' => [[], [convertedStore(['name' => 'eBay - otro_vendedor'])]],
    'two matching publications are ambiguous' => [[], [convertedStore(), convertedStore(['link' => 'https://www.ebay.com/itm/999', 'extracted_price' => 766.50])]],
    'different publication title' => [[], [convertedStore(['title' => 'Balatas traseras Toyota Corolla 2019'])]],
    'recovered offer does not declare a currency' => [[], [convertedStore(['alt_currency' => null])]],
    'recovered offer declares another currency' => [[], [convertedStore(['alt_currency' => '(EUR 41.22)'])]],
    'original offer was published in pesos' => [['moneda_original' => null, 'precio_moneda_original' => null], [convertedStore()]],
    'original publication title was not stored' => [['titulo_proveedor' => null], [convertedStore()]],
]);

test('added offers keep the foreign currency price reported by the provider', function () {
    [$order, $quotation] = partSearchDraft();
    Http::preventStrayRequests();
    Http::fake([
        'https://serpapi.com/search.json?*engine=google_shopping*' => Http::response(['shopping_results' => [
            sampledShoppingResult(CONVERSION_TITLE, 'eBay - autopartsgeek2', 766.74) + ['alternative_price' => ['price' => 'USD 41', 'extracted_price' => 41, 'currency' => 'USD']],
        ]], 200),
        'https://serpapi.com/search.json?*engine=google_product*' => Http::response(['product_results' => ['stores' => [convertedStore(['extracted_price' => 766.74, 'extracted_total' => 766.74])]]], 200),
    ]);
    $offer = $this->getJson(route('autopartes.search', $order).'?q=balatas')->json('offers.0');

    $this->postJson(route('autopartes.add', [$order, $quotation]), [
        'search_id' => $offer['search_id'], 'offer_id' => $offer['offer_id'], 'idempotency_key' => 'currency-evidence-key-0001',
    ])->assertOk();

    expect($quotation->detalles()->firstOrFail()->only(['precio_proveedor', 'moneda_original', 'precio_moneda_original', 'titulo_proveedor', 'url_origen']))->toBe([
        'precio_proveedor' => '766.74', 'moneda_original' => 'USD', 'precio_moneda_original' => '41.00',
        'titulo_proveedor' => CONVERSION_TITLE, 'url_origen' => 'https://www.ebay.com/itm/307094247793',
    ]);
});
