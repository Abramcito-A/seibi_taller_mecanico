@extends('layouts.app')

@section('title', 'Panel de taller')

@section('content')
    @php
        $roleLabels = [
            'administrador' => 'Administrador',
            'mecanico' => 'Mecánico',
            'recepcionista' => 'Recepcionista',
        ];
        $dashboardTitles = [
            'administrador' => 'Resumen del taller',
            'mecanico' => 'Trabajo técnico',
            'recepcionista' => 'Recepción y atención',
        ];
        $dashboardDescriptions = [
            'administrador' => 'Vista general de los registros y la actividad disponible en SEIBI.',
            'mecanico' => 'Consulta vehículos y revisa las órdenes que requieren atención técnica.',
            'recepcionista' => 'Acceso rápido a clientes, vehículos y recepción de servicio.',
        ];
    @endphp

    <x-ui.page-header :title="$dashboardTitles[$roleName] ?? 'Panel del taller'" :description="$dashboardDescriptions[$roleName] ?? 'Resumen de la operación actual del taller.'">
        <x-slot:actions><x-ui.badge tone="info">{{ $roleLabels[$roleName] ?? 'Personal' }}</x-ui.badge></x-slot:actions>
    </x-ui.page-header>

    @if ($roleName === 'administrador')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.panel title="Clientes" description="Personas registradas">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['clients']) }}</p>
                <x-slot:actions><x-ui.button :href="route('clientes.index')" variant="secondary">Ver clientes</x-ui.button></x-slot:actions>
            </x-ui.panel>
            <x-ui.panel title="Vehículos" description="Unidades registradas">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['vehicles']) }}</p>
                <x-slot:actions><x-ui.button :href="route('vehiculos.index')" variant="secondary">Ver vehículos</x-ui.button></x-slot:actions>
            </x-ui.panel>
            <x-ui.panel title="Órdenes" description="Órdenes de servicio guardadas">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['orders']) }}</p>
                @if ($counts['orders'] === 0)<p class="mt-2 text-sm text-slate-500">Aún no hay órdenes registradas.</p>@endif
                <x-slot:actions><x-ui.button :href="route('ordenes.index')" variant="secondary">Ver órdenes</x-ui.button></x-slot:actions>
            </x-ui.panel>
            <x-ui.panel title="Cotizaciones" description="Cotizaciones guardadas">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['quotes']) }}</p>
                @if ($counts['quotes'] === 0)<p class="mt-2 text-sm text-slate-500">Aún no hay cotizaciones registradas.</p>@endif
            </x-ui.panel>
        </div>

        <x-ui.alert variant="info" class="mt-5">
            Administración disponible. Puedes consultar expedientes técnicos y gestionar las cuentas internas desde
            <a href="{{ route('usuarios.index') }}" class="font-semibold underline underline-offset-2">Gestión de usuarios</a>.
        </x-ui.alert>
    @elseif ($roleName === 'mecanico')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.panel title="Órdenes recibidas" description="Pendientes de iniciar diagnóstico">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['ordersToDiagnose']) }}</p>
                <x-slot:actions><x-ui.button :href="route('ordenes.index', ['estado' => 'recibida'])" variant="secondary">Ver órdenes</x-ui.button></x-slot:actions>
            </x-ui.panel>
            <x-ui.panel title="Vehículos" description="Consulta expedientes y datos del vehículo">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['vehicles']) }}</p>
                <x-slot:actions><x-ui.button :href="route('vehiculos.index')" variant="secondary">Consultar vehículos</x-ui.button></x-slot:actions>
            </x-ui.panel>
            <x-ui.panel title="Clientes" description="Consulta información de contacto">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['clients']) }}</p>
                <x-slot:actions><x-ui.button :href="route('clientes.index')" variant="secondary">Consultar clientes</x-ui.button></x-slot:actions>
            </x-ui.panel>
        </div>

        <x-ui.panel title="Actividad técnica" description="Órdenes del taller por estado" class="mt-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-lg border border-seibi-border bg-seibi-workspace p-4">
                    <p class="text-sm font-medium text-slate-600">En diagnóstico</p>
                    <p class="mt-2 text-2xl font-bold text-seibi-ink">{{ number_format($counts['diagnosisQueue']) }}</p>
                </div>
                <div class="rounded-lg border border-seibi-border bg-seibi-workspace p-4">
                    <p class="text-sm font-medium text-slate-600">En reparación</p>
                    <p class="mt-2 text-2xl font-bold text-seibi-ink">{{ number_format($counts['repairQueue']) }}</p>
                </div>
            </div>
            @if ($counts['diagnosisQueue'] === 0 && $counts['repairQueue'] === 0)
                <p class="mt-4 text-sm text-slate-500">No hay órdenes pendientes de diagnóstico o reparación.</p>
            @endif
        </x-ui.panel>

        <x-ui.alert variant="info" class="mt-5">El registro completo de diagnósticos, cotizaciones y búsqueda de autopartes se habilitará en módulos posteriores.</x-ui.alert>
    @elseif ($roleName === 'recepcionista')
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.panel title="Clientes" description="Registro y búsqueda de clientes">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['clients']) }}</p>
                <x-slot:actions>
                    <x-ui.button :href="route('clientes.index')" variant="secondary">Buscar</x-ui.button>
                    <x-ui.button :href="route('clientes.create')">Nuevo cliente</x-ui.button>
                </x-slot:actions>
            </x-ui.panel>
            <x-ui.panel title="Vehículos" description="Registro y consulta de vehículos">
                <p class="text-3xl font-bold text-seibi-ink">{{ number_format($counts['vehicles']) }}</p>
                <x-slot:actions>
                    <x-ui.button :href="route('vehiculos.index')" variant="secondary">Buscar</x-ui.button>
                    <x-ui.button :href="route('vehiculos.create')">Nuevo vehículo</x-ui.button>
                </x-slot:actions>
            </x-ui.panel>
        </div>

        <x-ui.panel title="Recepción de servicio" description="Órdenes que requieren seguimiento inicial" class="mt-5">
            <x-slot:actions>
                <x-ui.button :href="route('ordenes.index')" variant="secondary">Ver órdenes</x-ui.button>
                <x-ui.button :href="route('ordenes.create')" icon="plus">Nueva recepción</x-ui.button>
            </x-slot:actions>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-lg border border-seibi-border bg-seibi-workspace p-4">
                    <p class="text-sm font-medium text-slate-600">Recibidas</p>
                    <p class="mt-2 text-2xl font-bold text-seibi-ink">{{ number_format($counts['receivedOrders']) }}</p>
                </div>
                <div class="rounded-lg border border-seibi-border bg-seibi-workspace p-4">
                    <p class="text-sm font-medium text-slate-600">Listas para entrega</p>
                    <p class="mt-2 text-2xl font-bold text-seibi-ink">{{ number_format($counts['readyForDelivery']) }}</p>
                </div>
            </div>
            @if ($counts['receivedOrders'] === 0 && $counts['readyForDelivery'] === 0)
                <p class="mt-4 text-sm text-slate-500">No hay órdenes en recepción ni listas para entrega.</p>
            @endif
        </x-ui.panel>

        <x-ui.panel title="Órdenes recientes" description="Registro real del taller, incluyendo órdenes creadas por cualquier recepcionista" class="mt-5" padding="p-0">
            @if ($orders->isEmpty())
                <x-ui.empty-state title="Sin órdenes registradas" description="Las recepciones aparecerán aquí cuando se guarde una nueva orden." icon="clipboard-list" class="min-h-48" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-seibi-border">
                        <thead class="bg-seibi-workspace"><tr><th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Folio</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Cliente</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Vehículo</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Ingreso</th><th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Estado</th><th class="px-4 py-3"></th></tr></thead>
                        <tbody class="divide-y divide-seibi-border bg-white">
                            @foreach ($orders as $order)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-seibi-ink">{{ $order->folio }}</td>
                                    <td class="px-4 py-3 text-sm text-seibi-ink">{{ $order->vehiculo->cliente->nombre }} {{ $order->vehiculo->cliente->apellido }}</td>
                                    <td class="px-4 py-3 text-sm text-slate-600">{{ $order->vehiculo->marca }} {{ $order->vehiculo->modelo }}</td>
                                    <td class="px-4 py-3 text-sm text-slate-600">{{ $order->fecha_ingreso?->format('d/m/Y H:i') }}</td>
                                    <td class="px-4 py-3 text-sm text-slate-600">{{ $order->estado }}</td>
                                    <td class="px-4 py-3 text-right"><a href="{{ route('ordenes.show', $order) }}" class="text-sm font-semibold text-seibi-navy hover:underline">Expediente</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.panel>
    @endif
@endsection