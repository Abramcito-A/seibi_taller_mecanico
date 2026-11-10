@extends('layouts.app')

@section('title', $cliente->nombre . ' ' . $cliente->apellido)

@section('content')
    <x-ui.page-header :title="$cliente->nombre . ' ' . $cliente->apellido" :description="'Cliente #' . $cliente->id_cliente">
        <x-slot:actions>
            <x-ui.back-button :fallback="route('clientes.index')" />
            @if (auth()->user()->hasRole('administrador', 'recepcionista'))
                <x-ui.button :href="route('clientes.edit', $cliente)" variant="secondary">Editar</x-ui.button>
            @endif
            @if (auth()->user()->hasRole('administrador'))
                <form id="delete-cliente-{{ $cliente->id_cliente }}" method="POST" action="{{ route('clientes.destroy', $cliente) }}">
                    @csrf
                    @method('DELETE')
                    <x-ui.confirm-trigger :form-id="'delete-cliente-' . $cliente->id_cliente" title="Eliminar cliente" message="¿Deseas eliminar a este cliente? Esta acción no se puede deshacer." class="min-h-10">Eliminar</x-ui.confirm-trigger>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mb-6">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Teléfono</dt><dd class="mt-1.5 text-sm font-medium text-seibi-ink">{{ $cliente->telefono }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Correo</dt><dd class="mt-1.5 text-sm text-seibi-ink">{{ $cliente->correo ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Dirección</dt><dd class="mt-1.5 text-sm text-seibi-ink">{{ $cliente->direccion ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Fecha de registro</dt><dd class="mt-1.5 text-sm text-seibi-ink">{{ $cliente->fecha_registro ? \Carbon\Carbon::parse($cliente->fecha_registro)->format('d/m/Y H:i') : '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Última actualización</dt><dd class="mt-1.5 text-sm text-seibi-ink">{{ $cliente->fecha_actualizacion ? \Carbon\Carbon::parse($cliente->fecha_actualizacion)->format('d/m/Y H:i') : '—' }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.panel title="Vehículos" :description="$cliente->vehiculos->count() . ' registrados'" padding="p-0">
        @if (auth()->user()->hasRole('administrador', 'recepcionista'))
            <x-slot:actions><x-ui.button :href="route('vehiculos.create', ['cliente_id' => $cliente->id_cliente])" icon="plus">Agregar vehículo</x-ui.button></x-slot:actions>
        @endif
        @if ($cliente->vehiculos->isEmpty())
            <x-ui.empty-state title="Sin vehículos registrados" description="Vincula el primer vehículo con este cliente." icon="car-front" />
        @else
            <div class="overflow-x-auto">
                <table class="ui-table min-w-[600px]">
                    <thead><tr><th scope="col">Vehículo</th><th scope="col">Año</th><th scope="col">Placas</th><th scope="col">Kilometraje</th><th scope="col" class="text-right">Acciones</th></tr></thead>
                    <tbody>
                        @foreach ($cliente->vehiculos as $vehiculo)
                            <tr>
                                <td><a href="{{ route('vehiculos.show', $vehiculo) }}" class="font-semibold text-seibi-ink hover:text-status-info">{{ $vehiculo->marca }} {{ $vehiculo->modelo }}</a>@if ($vehiculo->version)<p class="mt-1 text-xs text-slate-500">{{ $vehiculo->version }}</p>@endif</td>
                                <td class="text-sm text-slate-600">{{ $vehiculo->anio }}</td>
                                <td class="text-sm text-slate-600">{{ $vehiculo->placas ?? '—' }}</td>
                                <td class="text-sm text-slate-600">{{ $vehiculo->kilometraje_actual ? number_format($vehiculo->kilometraje_actual) . ' km' : '—' }}</td>
                                <td><div class="flex justify-end gap-3"><a href="{{ route('vehiculos.show', $vehiculo) }}" class="text-sm font-semibold text-seibi-navy hover:underline">Ver</a>@if (auth()->user()->hasRole('administrador', 'recepcionista'))<a href="{{ route('vehiculos.edit', $vehiculo) }}" class="text-sm font-semibold text-slate-600 hover:underline">Editar</a>@endif</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.panel>
@endsection
