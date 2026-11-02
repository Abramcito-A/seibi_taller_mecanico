@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
    <x-ui.page-header title="Clientes" description="Consulta y administra la información de las personas atendidas por el taller.">
        @if (auth()->user()->hasRole('administrador', 'recepcionista'))
            <x-slot:actions><x-ui.button :href="route('clientes.create')" icon="plus">Nuevo cliente</x-ui.button></x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.panel class="mb-5" padding="p-4 sm:p-5">
        <form method="GET" action="{{ route('clientes.index') }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end">
            <x-ui.input name="q" label="Buscar clientes" :value="request('q')" placeholder="Nombre, apellido, teléfono o correo" />
            <x-ui.button type="submit" variant="secondary" icon="search">Buscar</x-ui.button>
            @if (request('q'))<x-ui.button :href="route('clientes.index')" variant="neutral">Limpiar</x-ui.button>@endif
        </form>
    </x-ui.panel>

    <x-ui.panel padding="p-0">
        @if ($clientes->isEmpty())
            <x-ui.empty-state title="No se encontraron clientes" :description="request('q') ? 'Prueba con otros términos de búsqueda.' : 'Registra el primer cliente para comenzar a organizar el trabajo del taller.'" icon="users">
                @if (! request('q') && auth()->user()->hasRole('administrador', 'recepcionista'))
                    <x-slot:action><x-ui.button :href="route('clientes.create')" icon="plus">Registrar cliente</x-ui.button></x-slot:action>
                @endif
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="ui-table min-w-[760px]">
                <thead>
                    <tr>
                        <th scope="col">Nombre</th>
                        <th scope="col">Teléfono</th>
                        <th scope="col">Correo</th>
                        <th scope="col">Vehículos</th>
                        <th scope="col">Registro</th>
                        <th scope="col" class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($clientes as $cliente)
                        <tr>
                            <td>
                                <a href="{{ route('clientes.show', $cliente) }}" class="font-semibold text-seibi-ink transition hover:text-status-info">{{ $cliente->nombre }} {{ $cliente->apellido }}</a>
                                @if ($cliente->direccion)<p class="mt-1 max-w-xs truncate text-xs text-slate-500">{{ $cliente->direccion }}</p>@endif
                            </td>
                            <td class="whitespace-nowrap text-sm text-slate-600">{{ $cliente->telefono }}</td>
                            <td class="text-sm text-slate-600">{{ $cliente->correo ?? '—' }}</td>
                            <td><x-ui.badge>{{ $cliente->vehiculos_count ?? $cliente->vehiculos()->count() }}</x-ui.badge></td>
                            <td class="whitespace-nowrap text-sm text-slate-500">{{ $cliente->fecha_registro ? \Carbon\Carbon::parse($cliente->fecha_registro)->format('d/m/Y') : '—' }}</td>
                            <td>
                                <div class="flex items-center justify-end gap-3 whitespace-nowrap">
                                    <a href="{{ route('clientes.show', $cliente) }}"
                                       class="text-sm font-semibold text-seibi-navy hover:underline">Ver</a>
                                    @if (auth()->user()->hasRole('administrador', 'recepcionista'))
                                        <a href="{{ route('clientes.edit', $cliente) }}" class="text-sm font-semibold text-slate-600 hover:text-seibi-ink hover:underline">Editar</a>
                                    @endif
                                    @if (auth()->user()->hasRole('administrador'))
                                        <form id="delete-cliente-{{ $cliente->id_cliente }}" method="POST" action="{{ route('clientes.destroy', $cliente) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.confirm-trigger :form-id="'delete-cliente-' . $cliente->id_cliente" title="Eliminar cliente" :message="'¿Deseas eliminar a ' . $cliente->nombre . ' ' . $cliente->apellido . '? Esta acción no se puede deshacer.'" variant="destructive-link" size="compact">Eliminar</x-ui.confirm-trigger>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>

            @if ($clientes->hasPages())
                <div class="border-t border-seibi-border px-5 py-4 sm:px-6">
                    <x-ui.pagination :paginator="$clientes" />
                </div>
            @endif
        @endif
    </x-ui.panel>

    <div class="mt-3 flex justify-end">
        <x-ui.badge>{{ $clientes->total() }} {{ $clientes->total() === 1 ? 'cliente encontrado' : 'clientes encontrados' }}</x-ui.badge>
    </div>
@endsection

