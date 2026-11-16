@extends('layouts.app')

@section('title', 'Nueva recepción')

@section('content')
    <x-ui.page-header title="Nueva recepción" description="Busca al cliente, confirma el vehículo y registra su estado inicial.">
        <x-slot:actions>
            <x-ui.back-button :fallback="route('ordenes.index')" />
            @if ($selectedClient && $selectedClient->vehiculos->isNotEmpty())
                <x-ui.button :href="route('vehiculos.create', ['cliente_id' => $selectedClient->id_cliente])" variant="secondary" icon="plus">Agregar vehículo</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel class="mb-5" title="Cliente y vehículo" description="Los vehículos disponibles se limitan al cliente seleccionado." padding="p-4 sm:p-5">
        <div class="relative" id="cliente-combobox">
            <label for="cliente_busqueda" class="mb-1.5 block text-sm font-semibold text-seibi-ink">Buscar cliente</label>
            <div class="flex gap-2">
                <input
                    id="cliente_busqueda"
                    type="search"
                    autocomplete="off"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="cliente_resultados"
                    aria-expanded="false"
                    placeholder="Nombre, apellido, teléfono o correo"
                    value="{{ $selectedClient ? $selectedClient->nombre.' '.$selectedClient->apellido : '' }}"
                    class="ui-input min-w-0 flex-1">
                @if ($selectedClient)
                    <button id="limpiar_cliente" type="button" class="rounded-lg border border-seibi-border px-3 text-sm font-semibold text-seibi-ink hover:bg-seibi-workspace">Limpiar</button>
                @endif
            </div>
            <ul id="cliente_resultados" role="listbox" class="absolute z-20 mt-1 hidden max-h-72 w-full overflow-y-auto rounded-lg border border-seibi-border bg-white shadow-lg"></ul>
            <p id="cliente_estado" class="mt-2 text-xs text-slate-500" aria-live="polite"></p>
        </div>
        <div class="mt-3 text-sm text-slate-600">
            ¿No existe? <a href="{{ route('clientes.create') }}" class="font-semibold text-seibi-navy underline underline-offset-2">Registrar cliente</a>
        </div>
    </x-ui.panel>

    @if (! $selectedClient)
        <x-ui.panel padding="p-0">
            <x-ui.empty-state title="Selecciona un cliente para continuar" description="Después podrás elegir uno de sus vehículos y registrar la recepción." icon="users" />
        </x-ui.panel>
    @elseif ($selectedClient->vehiculos->isEmpty())
        <x-ui.panel padding="p-0">
            <x-ui.empty-state title="Este cliente aún no tiene vehículos" description="Registra su vehículo antes de abrir una orden de servicio." icon="car-front">
                <x-slot:action><x-ui.button :href="route('vehiculos.create', ['cliente_id' => $selectedClient->id_cliente])" icon="plus">Registrar vehículo</x-ui.button></x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        <x-ui.card class="mx-auto max-w-4xl">
            <form method="POST" action="{{ route('ordenes.store') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                <input type="hidden" name="id_cliente" value="{{ $selectedClient->id_cliente }}">

                <div class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-ui.select name="id_vehiculo" label="Vehículo" required>
                            <option value="">Selecciona un vehículo</option>
                            @foreach ($selectedClient->vehiculos as $vehiculo)
                                <option value="{{ $vehiculo->id_vehiculo }}" {{ (string) old('id_vehiculo') === (string) $vehiculo->id_vehiculo ? 'selected' : '' }}>
                                    {{ $vehiculo->marca }} {{ $vehiculo->modelo }} · {{ $vehiculo->anio }}{{ $vehiculo->placas ? ' · '.$vehiculo->placas : '' }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <x-ui.input name="fecha_ingreso" label="Fecha y hora de ingreso" type="datetime-local" :value="old('fecha_ingreso', now()->format('Y-m-d\TH:i'))" required />
                    <x-ui.input name="kilometraje_ingreso" label="Kilometraje de ingreso" type="number" :value="old('kilometraje_ingreso')" min="0" />
                    <div class="sm:col-span-2"><x-ui.textarea name="descripcion_problema" label="Falla o servicio reportado" :value="old('descripcion_problema')" rows="4" required maxlength="5000" /></div>
                    <div class="sm:col-span-2"><x-ui.textarea name="observaciones" label="Observaciones de recepción" :value="old('observaciones')" rows="3" maxlength="5000" /></div>

                    <div class="space-y-2 sm:col-span-2">
                        <label for="fotografias" class="block text-sm font-semibold text-seibi-ink">Fotografías del estado inicial <span class="font-normal text-slate-500">(opcional)</span></label>
                        <input id="fotografias" name="fotografias[]" type="file" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="fotografias-ayuda" class="ui-input h-auto min-h-12 cursor-pointer py-2 file:mr-3 file:rounded-md file:border-0 file:bg-seibi-workspace file:px-3 file:py-2 file:text-sm file:font-semibold file:text-seibi-navy">
                        <p id="fotografias-ayuda" class="text-xs leading-5 text-slate-500">JPEG, JPG, PNG o WebP · máximo 5 MB por imagen · hasta 10 fotografías.</p>
                        <p id="fotografias-count" class="text-xs font-semibold text-slate-600" aria-live="polite">0 fotografías seleccionadas.</p>
                        <div id="fotografias-preview" class="grid grid-cols-2 gap-2 sm:grid-cols-4"></div>
                        @error('fotografias')<p class="text-xs font-medium text-status-error" role="alert">{{ $message }}</p>@enderror
                        @error('fotografias.*')<p class="text-xs font-medium text-status-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="flex flex-col-reverse justify-end gap-3 border-t border-seibi-border pt-5 sm:flex-row">
                    <x-ui.button :href="route('ordenes.index')" variant="neutral">Cancelar</x-ui.button>
                    <x-ui.button type="submit" icon="clipboard-list">Guardar recepción</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <script>
        (() => {
            const input = document.getElementById('cliente_busqueda');
            const results = document.getElementById('cliente_resultados');
            const status = document.getElementById('cliente_estado');
            const clearButton = document.getElementById('limpiar_cliente');
            let timer;
            let highlighted = -1;
            let matches = [];

            if (!input || !results || !status) return;

            const closeResults = () => {
                results.classList.add('hidden');
                input.setAttribute('aria-expanded', 'false');
                highlighted = -1;
            };

            const renderResults = (items) => {
                matches = items;
                results.replaceChildren();
                highlighted = -1;

                if (items.length === 0) {
                    status.textContent = input.value.trim() ? 'Sin resultados.' : '';
                    closeResults();
                    return;
                }

                items.forEach((cliente, index) => {
                    const item = document.createElement('li');
                    item.id = `cliente-opcion-${index}`;
                    item.setAttribute('role', 'option');
                    item.className = 'cursor-pointer px-3 py-2.5 text-sm hover:bg-seibi-workspace';
                    item.innerHTML = `<span class="block font-semibold text-seibi-ink"></span><span class="mt-0.5 block text-xs text-slate-500"></span>`;
                    item.querySelector('span:first-child').textContent = cliente.nombre_completo;
                    item.querySelector('span:last-child').textContent =
                        [cliente.telefono, cliente.correo].filter(Boolean).join(' · ');
                    item.addEventListener('mousedown', (event) => {
                        event.preventDefault();
                        selectClient(cliente);
                    });
                    results.appendChild(item);
                });

                status.textContent = `${items.length} resultado${items.length === 1 ? '' : 's'} encontrado${items.length === 1 ? '' : 's'}.`;
                results.classList.remove('hidden');
                input.setAttribute('aria-expanded', 'true');
            };

            const searchClients = async () => {
                const term = input.value.trim();
                if (term.length < 1) {
                    matches = [];
                    closeResults();
                    status.textContent = '';
                    return;
                }

                status.textContent = 'Cargando...';
                try {
                    const response = await fetch(`{{ route('clientes.search') }}?q=${encodeURIComponent(term)}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (!response.ok) throw new Error('Consulta no disponible');
                    const payload = await response.json();
                    renderResults(payload.data || []);
                } catch (error) {
                    closeResults();
                    status.textContent = 'Error al consultar clientes.';
                }
            };

            const selectClient = (cliente) => {
                closeResults();
                const url = new URL(window.location.href);
                url.searchParams.set('cliente_id', cliente.id);
                window.location.assign(url.toString());
            };

            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(searchClients, 250);
            });

            input.addEventListener('keydown', (event) => {
                const visibleOptions = Array.from(results.children);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    if (visibleOptions.length === 0) return;
                    event.preventDefault();
                    const direction = event.key === 'ArrowDown' ? 1 : -1;
                    highlighted = (highlighted + direction + visibleOptions.length) % visibleOptions.length;
                    visibleOptions.forEach((option, index) => option.classList.toggle('bg-seibi-workspace', index === highlighted));
                    input.setAttribute('aria-activedescendant', visibleOptions[highlighted].id);
                } else if (event.key === 'Enter' && highlighted >= 0) {
                    event.preventDefault();
                    selectClient(matches[highlighted]);
                } else if (event.key === 'Escape') {
                    closeResults();
                }
            });

            clearButton?.addEventListener('click', () => {
                window.location.assign('{{ route('ordenes.create') }}');
            });

            document.addEventListener('click', (event) => {
                if (!document.getElementById('cliente-combobox')?.contains(event.target)) closeResults();
            });
        })();

        (() => {
            const input = document.getElementById('fotografias');
            const preview = document.getElementById('fotografias-preview');
            const count = document.getElementById('fotografias-count');
            if (!input || !preview || !count) return;

            let selectedFiles = [];
            const redraw = () => {
                const transfer = new DataTransfer();
                selectedFiles.forEach((file) => transfer.items.add(file));
                input.files = transfer.files;
                preview.replaceChildren();

                selectedFiles.forEach((file, index) => {
                    const card = document.createElement('div');
                    card.className = 'overflow-hidden rounded-lg border border-seibi-border bg-white';
                    const image = document.createElement('img');
                    image.className = 'aspect-square w-full object-cover';
                    image.alt = file.name;
                    image.src = URL.createObjectURL(file);
                    const footer = document.createElement('div');
                    footer.className = 'flex items-center gap-2 p-2';
                    const name = document.createElement('span');
                    name.className = 'min-w-0 flex-1 truncate text-xs text-seibi-ink';
                    name.textContent = file.name;
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'text-xs font-semibold text-status-error';
                    remove.textContent = 'Eliminar';
                    remove.addEventListener('click', () => {
                        selectedFiles.splice(index, 1);
                        redraw();
                    });
                    footer.append(name, remove);
                    card.append(image, footer);
                    preview.appendChild(card);
                });

                count.textContent = `${selectedFiles.length} fotografía${selectedFiles.length === 1 ? '' : 's'} seleccionada${selectedFiles.length === 1 ? '' : 's'}.`;
            };

            input.addEventListener('change', () => {
                const incoming = Array.from(input.files || []);
                selectedFiles = [...selectedFiles, ...incoming].slice(0, 10);
                redraw();
            });
        })();
    </script>
@endsection