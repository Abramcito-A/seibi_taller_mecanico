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
        <form method="GET" action="{{ route('ordenes.create') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
            <x-ui.input name="q" label="Buscar cliente" :value="$search" placeholder="Nombre, teléfono o correo" />
            <x-ui.select name="cliente_id" label="Cliente">
                <option value="">Selecciona un cliente</option>
                @foreach ($clientes as $cliente)
                    <option value="{{ $cliente->id_cliente }}" {{ (int) request('cliente_id') === $cliente->id_cliente ? 'selected' : '' }}>
                        {{ $cliente->apellido }}, {{ $cliente->nombre }} · {{ $cliente->telefono }}{{ $cliente->correo ? ' · '.$cliente->correo : '' }}
                    </option>
                @endforeach
            </x-ui.select>
            <x-ui.button type="submit" variant="secondary">Cargar vehículos</x-ui.button>
        </form>
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
                        <p id="fotografias-ayuda" class="text-xs leading-5 text-slate-500">JPEG, PNG o WebP · máximo 5 MB por imagen · hasta 5 fotografías.</p>
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
@endsection