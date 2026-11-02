@extends('layouts.app')

@section('title', 'Editar hallazgo técnico')

@section('content')
    <nav aria-label="Ruta de navegación" class="mb-4 flex items-center gap-2 text-xs text-slate-500">
        <a href="{{ route('ordenes.index') }}" class="hover:text-seibi-navy">Órdenes de servicio</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('ordenes.show', $orden) }}" class="hover:text-seibi-navy">{{ $orden->folio }}</a>
        <span aria-hidden="true">/</span>
        <span class="font-medium text-seibi-ink">Editar hallazgo</span>
    </nav>

    <x-ui.page-header title="Editar hallazgo técnico" :description="$orden->folio">
        <x-slot:actions>
            <x-ui.back-button :fallback="route('ordenes.show', $orden)" />
        </x-slot:actions>
    </x-ui.page-header>

    @include('diagnosticos._form', [
        'formAction' => route('diagnosticos.update', [$orden, $diagnostico]),
        'submitLabel' => 'Actualizar hallazgo',
        'method' => 'PATCH',
    ])
@endsection
