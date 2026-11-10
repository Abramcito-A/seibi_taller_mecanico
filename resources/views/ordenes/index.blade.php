@extends('layouts.app')

@section('title', 'Órdenes de servicio')

@section('content')
    <x-ui.page-header title="Órdenes de servicio" description="Consulta las recepciones y el estado actual de cada vehículo.">
        @if (auth()->user()->hasRole('administrador', 'recepcionista'))
            <x-slot:actions><x-ui.button :href="route('ordenes.create')" icon="plus">Nueva recepción</x-ui.button></x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.panel class="mb-5" padding="p-4 sm:p-5">
        <form method="GET" action="{{ route('ordenes.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(13rem,0.65fr)_auto_auto] lg:items-end">
            <x-ui.input name="q" label="Buscar órdenes" :value="$filters['q']" placeholder="Folio, cliente, vehículo o placas" />
            <x-ui.select name="estado" label="Estado">
                <option value="">Todos los estados</option>
                @foreach ($statusLabels as $status => $label)
                    @if ($status !== 'esperando_refaccion')
                        <option value="{{ $status }}" {{ $filters['estado'] === $status ? 'selected' : '' }}>{{ $label }}</option>
                    @endif
                @endforeach
            </x-ui.select>
            <x-ui.button type="submit" variant="secondary" icon="search">Buscar</x-ui.button>
            @if ($filters['q'] !== '' || $filters['estado'])
                <x-ui.button :href="route('ordenes.index')" variant="neutral">Limpiar</x-ui.button>
            @endif
        </form>
    </x-ui.panel>

    <x-ui.panel padding="p-0">
        @if ($ordenes->isEmpty())
            <x-ui.empty-state title="No hay órdenes para mostrar" description="Cambia los filtros o crea una nueva recepción para iniciar un expediente." icon="clipboard-list">
                @if (auth()->user()->hasRole('administrador', 'recepcionista'))
                    <x-slot:action><x-ui.button :href="route('ordenes.create')" icon="plus">Nueva recepción</x-ui.button></x-slot:action>
                @endif
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="ui-table min-w-[820px]">
                    <thead>
                        <tr>
                            <th scope="col">Folio</th>
                            <th scope="col">Ingreso</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Vehículo</th>
                            <th scope="col">Kilometraje</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ordenes as $orden)
                            <tr>
                                <td><a href="{{ route('ordenes.show', $orden) }}" class="font-semibold text-seibi-navy hover:underline">{{ $orden->folio }}</a></td>
                                <td class="whitespace-nowrap text-sm text-slate-600">{{ $orden->fecha_ingreso->format('d/m/Y H:i') }}</td>
                                <td class="text-sm text-slate-600">{{ $orden->vehiculo->cliente->nombre }} {{ $orden->vehiculo->cliente->apellido }}</td>
                                <td class="text-sm text-slate-600">{{ $orden->vehiculo->marca }} {{ $orden->vehiculo->modelo }} <span class="text-slate-500">{{ $orden->vehiculo->placas }}</span></td>
                                <td class="whitespace-nowrap text-sm text-slate-600">{{ $orden->kilometraje_ingreso === null ? '—' : number_format($orden->kilometraje_ingreso).' km' }}</td>
                                <td><x-ui.badge :tone="$statusTones[$orden->estado] ?? 'neutral'">{{ $statusLabels[$orden->estado] ?? $orden->estado }}</x-ui.badge></td>
                                <td class="text-right"><a href="{{ route('ordenes.show', $orden) }}" class="text-sm font-semibold text-seibi-navy hover:underline">Abrir expediente</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($ordenes->hasPages())
                <div class="border-t border-seibi-border px-5 py-4 sm:px-6"><x-ui.pagination :paginator="$ordenes" /></div>
            @endif
        @endif
    </x-ui.panel>

    <div class="mt-3 flex justify-end"><x-ui.badge>{{ $ordenes->total() }} {{ $ordenes->total() === 1 ? 'orden' : 'órdenes' }}</x-ui.badge></div>
@endsection