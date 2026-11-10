@extends('layouts.app')

@section('title', 'Vehículos')

@section('content')
    <x-ui.page-header title="Vehículos" description="Mantén a la vista los vehículos, sus propietarios y los datos necesarios para su atención.">
        @if (auth()->user()->hasRole('administrador', 'recepcionista'))
            <x-slot:actions><x-ui.button :href="route('vehiculos.create')" icon="plus">Nuevo vehículo</x-ui.button></x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.panel class="mb-5" padding="p-4 sm:p-5">
        <form method="GET" action="{{ route('vehiculos.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(13rem,0.65fr)_auto_auto] lg:items-end">
            <x-ui.input name="q" label="Buscar vehículos" :value="request('q')" placeholder="Marca, modelo o placas" />
            <x-ui.select name="cliente_id" label="Cliente">
                <option value="">Todos los clientes</option>
                @foreach ($clientes as $c)
                    <option value="{{ $c->id_cliente }}" {{ request('cliente_id') == $c->id_cliente ? 'selected' : '' }}>
                        {{ $c->apellido }}, {{ $c->nombre }}
                    </option>
                @endforeach
            </x-ui.select>
            <x-ui.button type="submit" variant="secondary">Filtrar</x-ui.button>
            @if (request('q') || request('cliente_id'))<x-ui.button :href="route('vehiculos.index')" variant="neutral">Limpiar</x-ui.button>@endif
        </form>
    </x-ui.panel>

    <x-ui.panel padding="p-0">
        @if ($vehiculos->isEmpty())
            <x-ui.empty-state title="No se encontraron vehículos" :description="request('q') || request('cliente_id') ? 'Prueba con otros términos o cambia el filtro de cliente.' : 'Registra el primer vehículo para vincularlo con su propietario.'" icon="car-front">
                @if (! (request('q') || request('cliente_id')) && auth()->user()->hasRole('administrador', 'recepcionista'))
                    <x-slot:action><x-ui.button :href="route('vehiculos.create')" icon="plus">Registrar vehículo</x-ui.button></x-slot:action>
                @endif
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="ui-table min-w-[760px]">
                <thead>
                    <tr>
                        <th scope="col">Vehículo</th>
                        <th scope="col">Cliente</th>
                        <th scope="col">Placas</th>
                        <th scope="col">Año</th>
                        <th scope="col">Kilometraje</th>
                        <th scope="col" class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vehiculos as $vehiculo)
                        <tr>
                            <td>
                                <a href="{{ route('vehiculos.show', $vehiculo) }}" class="font-semibold text-seibi-ink transition hover:text-status-info">{{ $vehiculo->marca }} {{ $vehiculo->modelo }}</a>
                                @if ($vehiculo->version)<p class="mt-1 text-xs text-slate-500">{{ $vehiculo->version }}</p>@endif
                            </td>
                            <td class="text-sm">
                                @if ($vehiculo->cliente)
                                    <a href="{{ route('clientes.show', $vehiculo->cliente) }}"
                                       class="font-medium text-seibi-navy hover:underline">
                                        {{ $vehiculo->cliente->nombre }} {{ $vehiculo->cliente->apellido }}
                                    </a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-sm text-slate-600">{{ $vehiculo->placas ?? '—' }}</td>
                            <td class="text-sm text-slate-600">{{ $vehiculo->anio }}</td>
                            <td class="whitespace-nowrap text-sm text-slate-600">{{ $vehiculo->kilometraje_actual ? number_format($vehiculo->kilometraje_actual) . ' km' : '—' }}</td>
                            <td>
                                <div class="flex items-center justify-end gap-3 whitespace-nowrap">
                                    <a href="{{ route('vehiculos.show', $vehiculo) }}"
                                       class="text-sm font-semibold text-seibi-navy hover:underline">Ver</a>
                                    @if (auth()->user()->hasRole('administrador', 'recepcionista'))
                                        <a href="{{ route('vehiculos.edit', $vehiculo) }}" class="text-sm font-semibold text-slate-600 hover:text-seibi-ink hover:underline">Editar</a>
                                    @endif
                                    @if (auth()->user()->hasRole('administrador'))
                                        <form id="delete-vehiculo-{{ $vehiculo->id_vehiculo }}" method="POST" action="{{ route('vehiculos.destroy', $vehiculo) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.confirm-trigger :form-id="'delete-vehiculo-' . $vehiculo->id_vehiculo" title="Eliminar vehículo" message="¿Deseas eliminar este vehículo? Esta acción no se puede deshacer." variant="destructive-link" size="compact">Eliminar</x-ui.confirm-trigger>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>

            @if ($vehiculos->hasPages())
                <div class="border-t border-seibi-border px-5 py-4 sm:px-6">
                    <x-ui.pagination :paginator="$vehiculos" />
                </div>
            @endif
        @endif
    </x-ui.panel>

    <div class="mt-3 flex justify-end">
        <x-ui.badge>{{ $vehiculos->total() }} {{ $vehiculos->total() === 1 ? 'vehículo encontrado' : 'vehículos encontrados' }}</x-ui.badge>
    </div>
@endsection

