<x-ui.card>
    <form method="POST" action="{{ $formAction }}" class="space-y-6" data-cotizacion-form>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="titulo" class="mb-2 block text-sm font-semibold text-seibi-ink">Nombre del trabajo</label>
                <input id="titulo" name="titulo" value="{{ old('titulo', $cotizacion->titulo ?? '') }}" required maxlength="150" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">
                @error('titulo')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="tipo" class="mb-2 block text-sm font-semibold text-seibi-ink">Tipo de trabajo</label>
                <select id="tipo" name="tipo" required class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">
                    @foreach ($workTypes as $workType)
                        <option value="{{ $workType }}" @selected(old('tipo', $cotizacion->tipo ?? 'reparacion') === $workType)>{{ ucfirst(str_replace('_', ' ', $workType)) }}</option>
                    @endforeach
                </select>
                @error('tipo')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label for="descripcion" class="mb-2 block text-sm font-semibold text-seibi-ink">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="3" maxlength="2000" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">{{ old('descripcion', $cotizacion->descripcion ?? '') }}</textarea>
            @error('descripcion')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="rounded-lg border border-seibi-border bg-seibi-workspace/30 p-4" data-part-search>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="part-search-input" class="mb-2 block text-sm font-semibold text-seibi-ink">Buscar refacción</label>
                    <input id="part-search-input" type="search" maxlength="150" placeholder="Ej. balatas delanteras" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">
                    <p class="mt-1.5 text-xs text-slate-500">Búsqueda automática para {{ $orden->vehiculo->marca }} {{ $orden->vehiculo->modelo }} {{ $orden->vehiculo->anio }}{{ $orden->vehiculo->version ? ' · '.$orden->vehiculo->version : '' }}.</p>
                    <fieldset class="mt-3 flex flex-wrap gap-4" aria-label="Modalidad de búsqueda">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="search_mode" value="compatible" checked class="text-seibi-navy">
                            Buscar para este vehículo
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="search_mode" value="general" class="text-seibi-navy">
                            Búsqueda general
                        </label>
                    </fieldset>
                </div>
                <x-ui.button type="button" variant="secondary" icon="search" data-search-parts>Buscar ofertas</x-ui.button>
            </div>
            <p class="mt-3 hidden text-sm text-slate-600" data-search-status role="status" aria-live="polite"></p>
            <p class="mt-3 hidden text-xs text-amber-800" data-search-warning>Confirma manualmente que la pieza sea compatible antes de autorizar el presupuesto.</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-search-results></div>
        </div>

        <div>
            <div class="mb-3 flex items-center justify-between gap-3">
                <div><h2 class="text-sm font-semibold text-seibi-ink">Conceptos</h2><p class="text-xs text-slate-500">Piezas, servicios, mano de obra u otros conceptos.</p></div>
                <x-ui.button type="button" variant="secondary" size="compact" data-add-detail>Agregar concepto</x-ui.button>
            </div>
            @error('detalles')<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror

            <div class="space-y-3" data-detail-list>
                @php
                    $oldDetails = old('detalles');
                    if ($oldDetails === null && isset($cotizacion)) {
                        $oldDetails = $cotizacion->detalles->map(fn ($detail) => $detail->only(['tipo_concepto','descripcion','cantidad','precio_unitario','moneda','tienda_origen','marca','numero_parte','url_origen','imagen_url']))->all();
                    }
                    $oldDetails = $oldDetails ?: [[
                        'tipo_concepto' => 'pieza', 'descripcion' => '', 'cantidad' => '1.00',
                        'precio_unitario' => '0.00', 'moneda' => 'MXN', 'tienda_origen' => '',
                        'marca' => '', 'numero_parte' => '', 'url_origen' => '', 'imagen_url' => '',
                    ]];
                @endphp
                @foreach ($oldDetails as $index => $detail)
                    @include('cotizaciones._detail-row', ['detail' => $detail, 'index' => $index])
                @endforeach
            </div>
        </div>

        <div class="flex flex-col items-end border-t border-seibi-border pt-5 sm:flex-row sm:justify-end sm:gap-6">
            <dl class="grid min-w-64 grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="font-semibold text-slate-600">Subtotal</dt><dd class="text-right font-semibold text-seibi-ink" data-subtotal>$ 0.00 MXN</dd>
                <dt class="text-base font-bold text-seibi-ink">Total</dt><dd class="text-right text-base font-bold text-seibi-ink" data-total>$ 0.00 MXN</dd>
            </dl>
        </div>

        <div>
            <label for="observaciones" class="mb-2 block text-sm font-semibold text-seibi-ink">Observaciones</label>
            <textarea id="observaciones" name="observaciones" rows="4" maxlength="5000" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">{{ old('observaciones', $cotizacion->observaciones ?? '') }}</textarea>
            @error('observaciones')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <x-ui.button :href="$cancelUrl" variant="neutral">Cancelar</x-ui.button>
            <x-ui.button type="submit" icon="save">{{ $submitLabel }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<template data-detail-template>
    @include('cotizaciones._detail-row', ['detail' => ['tipo_concepto'=>'pieza','descripcion'=>'','cantidad'=>'1.00','precio_unitario'=>'0.00','moneda'=>'MXN','tienda_origen'=>'','marca'=>'','numero_parte'=>'','url_origen'=>'','imagen_url'=>''], 'index' => '__INDEX__'])
</template>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('[data-cotizacion-form]');
        if (!form) return;
        const list = form.querySelector('[data-detail-list]');
        const template = document.querySelector('[data-detail-template]');
        const searchUrl = @json($searchUrl);
        const detailsUrl = @json($detailsUrl ?? null);
        const offerAddUrl = @json($offerAddUrl ?? null);
        let nextIndex = list.querySelectorAll('[data-detail-row]').length;

        const money = value => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
        const adjustQuantityInput = row => {
            const type = row.querySelector('[data-detail-type]');
            const quantity = row.querySelector('[data-quantity-input]');
            const label = row.querySelector('[data-quantity-label]');
            if (!type || !quantity || !label) return;
            if (type.value === 'mano_obra') {
                label.textContent = 'Horas';
                quantity.min = '0.01';
                quantity.step = '0.25';
            } else {
                label.textContent = 'Cantidad';
                quantity.min = '1';
                quantity.step = '1';
                if (quantity.value !== '' && !Number.isInteger(Number(quantity.value))) {
                    quantity.value = '1.00';
                }
            }
        };
        const recalculate = () => {
            let subtotal = 0;
            list.querySelectorAll('[data-detail-row]').forEach(row => {
                const quantity = Number.parseFloat(row.querySelector('[name$="[cantidad]"]').value) || 0;
                const price = Number.parseFloat(row.querySelector('[name$="[precio_unitario]"]').value) || 0;
                const amount = quantity * price;
                row.querySelector('[data-line-total]').textContent = money(amount);
                subtotal += amount;
            });
            form.querySelector('[data-subtotal]').textContent = money(subtotal).replace('MX$', '$').replace('MXN', '') + ' MXN';
            form.querySelector('[data-total]').textContent = money(subtotal).replace('MX$', '$').replace('MXN', '') + ' MXN';
        };

        form.addEventListener('input', recalculate);
        form.addEventListener('change', event => {
            const type = event.target.closest('[data-detail-type]');
            if (type) {
                adjustQuantityInput(type.closest('[data-detail-row]'));
            }
        });
        form.addEventListener('click', event => {
            if (event.target.closest('[data-add-detail]')) {
                const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
                list.insertAdjacentHTML('beforeend', html);
                adjustQuantityInput(list.lastElementChild);
                recalculate();
            }
            const remove = event.target.closest('[data-remove-detail]');
            if (remove) {
                if (list.querySelectorAll('[data-detail-row]').length === 1) return;
                remove.closest('[data-detail-row]').remove();
                list.querySelectorAll('[data-detail-row]').forEach(adjustQuantityInput);
                recalculate();
            }
        });

        const searchPanel = form.querySelector('[data-part-search]');
        if (searchPanel) {
            const searchInput = searchPanel.querySelector('#part-search-input');
            const status = searchPanel.querySelector('[data-search-status]');
            const warning = searchPanel.querySelector('[data-search-warning]');
            const results = searchPanel.querySelector('[data-search-results]');

            searchPanel.querySelector('[data-search-parts]').addEventListener('click', async () => {
                const query = searchInput.value.trim();
                const mode = searchPanel.querySelector('input[name="search_mode"]:checked')?.value || 'compatible';
                if (query.length < 2) {
                    status.textContent = 'Ingresa al menos 2 caracteres para buscar.';
                    status.classList.remove('hidden');
                    return;
                }

                status.textContent = 'Consultando ofertas...';
                status.classList.remove('hidden');
                warning.classList.add('hidden');
                results.replaceChildren();

                try {
                    const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}&mode=${encodeURIComponent(mode)}`, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const payload = await response.json();
                    status.textContent = payload.message || (payload.status === 'success' ? `Consulta: ${payload.query}` : '');

                    if (payload.status !== 'success' || !Array.isArray(payload.offers)) {
                        warning.classList.remove('hidden');
                        return;
                    }

                    warning.classList.remove('hidden');
                    payload.offers.forEach(offer => {
                        const card = document.createElement('article');
                        card.className = 'flex flex-col rounded-lg border border-seibi-border bg-white p-3';

                        // ── Imagen del producto ─────────────────────────────────────────────────
                        if (offer.image_url) {
                            const image = document.createElement('img');
                            image.src = offer.image_url;
                            image.alt = '';
                            image.className = 'mb-3 h-32 w-full rounded-md object-contain';
                            card.appendChild(image);
                        }

                        // ── Título ──────────────────────────────────────────────────────────────
                        const title = document.createElement('h3');
                        title.className = 'text-sm font-bold text-seibi-ink';
                        title.textContent = offer.title;
                        card.appendChild(title);

                        // ── Comercio (source = tienda que publica, no fabricante) ───────────────
                        const store = document.createElement('p');
                        store.className = 'mt-1 text-xs text-slate-500';
                        store.textContent = offer.store_name || 'Comercio no disponible';
                        card.appendChild(store);

                        // ── Marca del fabricante (solo si el proveedor la proporciona) ──────────
                        const brand = document.createElement('p');
                        brand.className = 'mt-1 text-xs text-slate-600';
                        if (offer.brand) {
                            brand.textContent = `Marca: ${offer.brand}`;
                            card.appendChild(brand);
                        }

                        // ── Número de parte (solo si el proveedor lo proporciona) ───────────────
                        const partNumber = document.createElement('p');
                        partNumber.className = 'mt-1 text-xs text-slate-600';
                        if (offer.part_number) {
                            partNumber.textContent = `Número de parte: ${offer.part_number}`;
                            card.appendChild(partNumber);
                        }

                        // ── Precio ──────────────────────────────────────────────────────────────
                        const price = document.createElement('p');
                        price.className = 'mt-2 text-sm font-bold text-seibi-navy';
                        price.textContent = offer.price_display || 'Precio no disponible';
                        card.appendChild(price);

                        // ── Enlace de publicación (URL de compra, no la imagen) ─────────────────
                        let publicationLink = null;
                        if (offer.url) {
                            const link = document.createElement('a');
                            link.href = offer.url;
                            link.target = '_blank';
                            link.rel = 'noopener noreferrer';
                            link.className = 'mt-2 text-xs font-semibold text-seibi-navy hover:underline';
                            link.textContent = offer.url.includes('google.com/shopping')
                                ? 'Enlace de consulta de Google Shopping'
                                : 'Ver publicación del comercio';
                            card.appendChild(link);
                            publicationLink = link;
                        }

                        // ── Botón "Ver detalles técnicos" ───────────────────────────────────────
                        const detailsButton = document.createElement('button');
                        detailsButton.type = 'button';
                        detailsButton.className = 'mt-2 text-xs font-semibold text-seibi-navy hover:underline';
                        detailsButton.textContent = 'Ver detalles técnicos';
                        const detailsPanel = document.createElement('div');
                        detailsPanel.className = 'mt-2 hidden rounded-md border border-seibi-border bg-seibi-workspace/40 p-2 text-xs text-slate-600';
                        let technicalDetailsLoaded = false;

                        // Rellena el panel con los datos disponibles de la oferta
                        const populateDetailsPanel = (source) => {
                            detailsPanel.replaceChildren();
                            const appendParagraph = (label, value) => {
                                const paragraph = document.createElement('p');
                                const strong = document.createElement('strong');
                                strong.textContent = `${label}: `;
                                paragraph.append(strong, document.createTextNode(value));
                                detailsPanel.appendChild(paragraph);
                            };
                            if (source.brand) appendParagraph('Marca', source.brand);
                            if (source.part_number) appendParagraph('Número de parte', source.part_number);
                            if (source.store_name) appendParagraph('Comercio', source.store_name);

                            const appendTechnicalList = (items, label) => {
                                if (!Array.isArray(items) || items.length === 0) return;
                                const heading = document.createElement('p');
                                heading.className = 'mt-2 font-semibold';
                                heading.textContent = label;
                                const ul = document.createElement('ul');
                                ul.className = 'list-disc pl-4';
                                items.forEach(item => {
                                    const row = document.createElement('li');
                                    if (typeof item === 'string') {
                                        row.textContent = item;
                                    } else {
                                        const itemTitle = item.title || item.name || '';
                                        const itemValue = item.value || item.description || '';
                                        row.textContent = itemTitle ? `${itemTitle}: ${itemValue}` : itemValue;
                                    }
                                    ul.appendChild(row);
                                });
                                detailsPanel.append(heading, ul);
                            };
                            const td = source.technical_details || {};
                            appendTechnicalList(td.features, 'Características');
                            appendTechnicalList(td.specifications, 'Especificaciones');
                            appendTechnicalList(td.compatibility, 'Compatibilidad');
                            if (detailsPanel.childElementCount === 0) {
                                const empty = document.createElement('p');
                                empty.textContent = 'El proveedor no proporcionó detalles técnicos adicionales.';
                                detailsPanel.appendChild(empty);
                            }
                        };

                        detailsButton.addEventListener('click', async () => {
                            if (technicalDetailsLoaded) {
                                const isHidden = detailsPanel.classList.toggle('hidden');
                                detailsButton.textContent = isHidden ? 'Ver detalles técnicos' : 'Ocultar detalles técnicos';
                                return;
                            }

                            // Si no hay URL de detalles o el producto no tiene token/id que enriquecer,
                            // mostramos la información local disponible sin hacer petición adicional.
                            const hasEnrichmentToken = !!(offer.provider_product_id || offer.page_token);
                            if (!detailsUrl || !hasEnrichmentToken) {
                                populateDetailsPanel(offer);
                                technicalDetailsLoaded = true;
                                detailsPanel.classList.remove('hidden');
                                detailsButton.textContent = 'Ocultar detalles técnicos';
                                return;
                            }

                            detailsButton.disabled = true;
                            detailsButton.textContent = 'Consultando detalles...';
                            try {
                                const params = new URLSearchParams({
                                    search_id: offer.search_id,
                                    offer_id: offer.offer_id,
                                });
                                const detailsResponse = await fetch(`${detailsUrl}?${params.toString()}`, {
                                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                                });
                                const detailsPayload = await detailsResponse.json();
                                if (!detailsResponse.ok) throw new Error(detailsPayload.message || 'No fue posible consultar los detalles.');

                                const enriched = detailsPayload.offer || offer;

                                // Actualizar marca en la tarjeta si el enriquecimiento la proporciona
                                if (enriched.brand) {
                                    brand.textContent = `Marca: ${enriched.brand}`;
                                    if (!brand.isConnected) card.insertBefore(brand, partNumber.isConnected ? partNumber : price);
                                }
                                // Actualizar número de parte en la tarjeta si está disponible
                                if (enriched.part_number) {
                                    partNumber.textContent = `Número de parte: ${enriched.part_number}`;
                                    if (!partNumber.isConnected) card.insertBefore(partNumber, price);
                                }
                                // Actualizar enlace de publicación (URL de compra, no imagen)
                                if (enriched.url) {
                                    if (!publicationLink) {
                                        publicationLink = document.createElement('a');
                                        publicationLink.target = '_blank';
                                        publicationLink.rel = 'noopener noreferrer';
                                        publicationLink.className = 'mt-2 text-xs font-semibold text-seibi-navy hover:underline';
                                        card.insertBefore(publicationLink, detailsButton);
                                    }
                                    publicationLink.href = enriched.url;
                                    publicationLink.textContent = enriched.url.includes('google.com/shopping')
                                        ? 'Enlace de consulta de Google Shopping'
                                        : 'Ver publicación del comercio';
                                }

                                populateDetailsPanel(enriched);
                                technicalDetailsLoaded = true;
                                detailsPanel.classList.remove('hidden');
                                detailsButton.disabled = false;
                                detailsButton.textContent = 'Ocultar detalles técnicos';
                            } catch (error) {
                                detailsButton.disabled = false;
                                detailsButton.textContent = 'Ver detalles técnicos';
                                status.textContent = error.message || 'No fue posible consultar los detalles técnicos.';
                                status.classList.remove('hidden');
                            }
                        });
                        card.append(detailsButton, detailsPanel);

                        // ── Botón "Agregar a cotización" ────────────────────────────────────────
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'mt-auto pt-3 text-sm font-semibold text-seibi-navy disabled:cursor-not-allowed disabled:text-slate-400';
                        const numericPrice = Number.parseFloat(offer.price_numeric);
                        const priceOk = Number.isFinite(numericPrice) && numericPrice >= 0 && offer.currency === 'MXN';
                        const canAdd = priceOk && !!offerAddUrl;
                        button.disabled = !canAdd;
                        if (!priceOk) {
                            button.textContent = (!Number.isFinite(numericPrice) || numericPrice < 0)
                                ? 'Sin precio confiable'
                                : 'Moneda no disponible';
                        } else if (!offerAddUrl) {
                            // No hay cotización activa aún (situación transitoria)
                            button.textContent = 'Cotización no disponible';
                        } else {
                            button.textContent = 'Agregar a cotización';
                        }
                        button.addEventListener('click', async () => {
                            if (!offerAddUrl) return;
                            button.disabled = true;
                            button.textContent = 'Agregando...';
                            const idempotencyKey = globalThis.crypto?.randomUUID?.()
                                ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;

                            try {
                                const token = form.querySelector('input[name="_token"]')?.value;
                                const response = await fetch(offerAddUrl, {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': token || '',
                                        'X-Idempotency-Key': idempotencyKey,
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    body: JSON.stringify({
                                        search_id: offer.search_id,
                                        offer_id: offer.offer_id,
                                        idempotency_key: idempotencyKey,
                                    }),
                                });
                                const result = await response.json();
                                if (!response.ok) {
                                    throw new Error(result.message || 'No fue posible agregar la refacción.');
                                }
                                status.textContent = result.message;
                                status.classList.remove('hidden');
                                button.textContent = 'Agregada';
                                window.location.href = result.redirect_url;
                            } catch (error) {
                                button.disabled = false;
                                button.textContent = 'Agregar a cotización';
                                status.textContent = error.message || 'No fue posible agregar la refacción.';
                                status.classList.remove('hidden');
                            }
                        });
                        card.appendChild(button);
                        results.appendChild(card);
                    });
                } catch (error) {
                    status.textContent = 'No fue posible consultar el proveedor comercial.';
                    status.classList.remove('hidden');
                }
            });
        }
        recalculate();
    });
</script>
