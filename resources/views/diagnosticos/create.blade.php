@extends('layouts.app')

@section('title', 'Nuevo hallazgo técnico')

@section('content')
    <nav aria-label="Ruta de navegación" class="mb-4 flex items-center gap-2 text-xs text-slate-500">
        <a href="{{ route('ordenes.index') }}" class="hover:text-seibi-navy">Órdenes de servicio</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos']) }}" class="hover:text-seibi-navy">{{ $orden->folio }}</a>
        <span aria-hidden="true">/</span>
        <span class="font-medium text-seibi-ink">Nuevo hallazgo</span>
    </nav>

    <x-ui.page-header title="Nuevo hallazgo técnico" :description="$orden->folio.' · '.$orden->vehiculo->marca.' '.$orden->vehiculo->modelo">
        <x-slot:actions>
            <x-ui.button
                :href="route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos'])"
                variant="neutral"
            >Volver al expediente</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('diagnosticos._form', [
        'formAction' => route('diagnosticos.store', $orden),
        'submitLabel' => 'Guardar hallazgo',
        'method' => 'POST',
        'envioUuid' => $envioUuid,
        'statusUrl' => route('diagnosticos.submission-status', [$orden, $envioUuid]),
        'newFormUrl' => route('diagnosticos.create', $orden),
    ])
@endsection
