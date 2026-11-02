@extends('layouts.app')

@section('title', $vehiculo->marca . ' ' . $vehiculo->modelo)

@section('content')
    <x-ui.page-header :title="$vehiculo->marca . ' ' . $vehiculo->modelo" :description="'Vehículo #' . $vehiculo->id_vehiculo . ' · ' . $vehiculo->anio . ($vehiculo->version ? ' · ' . $vehiculo->version : '')">
        <x-slot:actions>
            <x-ui.back-button :fallback="route('vehiculos.index')" />
            @if (auth()->user()->hasRole('administrador', 'recepcionista'))
                <x-ui.button :href="route('vehiculos.edit', $vehiculo)" variant="secondary">Editar</x-ui.button>
            @endif
            @if (auth()->user()->hasRole('administrador'))
                <form id="delete-vehiculo-{{ $vehiculo->id_vehiculo }}" method="POST" action="{{ route('vehiculos.destroy', $vehiculo) }}">
                    @csrf
                    @method('DELETE')
                    <x-ui.confirm-trigger :form-id="'delete-vehiculo-' . $vehiculo->id_vehiculo" title="Eliminar vehículo" message="¿Deseas eliminar este vehículo? Esta acción no se puede deshacer." class="min-h-10">Eliminar</x-ui.confirm-trigger>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Cliente</dt><dd class="mt-1.5 text-sm">@if ($vehiculo->cliente)<a href="{{ route('clientes.show', $vehiculo->cliente) }}" class="font-medium text-seibi-navy hover:underline">{{ $vehiculo->cliente->nombre }} {{ $vehiculo->cliente->apellido }}</a>@else<span class="text-slate-400">—</span>@endif</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Placas</dt><dd class="mt-1.5 text-sm text-seibi-ink">{{ $vehiculo->placas ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Número de serie (VIN)</dt><dd class="mt-1.5 break-all font-mono text-sm text-seibi-ink">{{ $vehiculo->numero_serie ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Kilometraje</dt><dd class="mt-1.5 text-sm text-seibi-ink">{{ $vehiculo->kilometraje_actual ? number_format($vehiculo->kilometraje_actual) . ' km' : '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Fecha de registro</dt><dd class="mt-1.5 text-sm text-seibi-ink">{{ $vehiculo->fecha_registro ? \Carbon\Carbon::parse($vehiculo->fecha_registro)->format('d/m/Y H:i') : '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Última actualización</dt><dd class="mt-1.5 text-sm text-seibi-ink">{{ $vehiculo->fecha_actualizacion ? \Carbon\Carbon::parse($vehiculo->fecha_actualizacion)->format('d/m/Y H:i') : '—' }}</dd></div>
            @if ($vehiculo->notas)<div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs font-semibold uppercase text-slate-500">Notas</dt><dd class="mt-1.5 whitespace-pre-line text-sm leading-6 text-seibi-ink">{{ $vehiculo->notas }}</dd></div>@endif
        </dl>
    </x-ui.card>
@endsection
