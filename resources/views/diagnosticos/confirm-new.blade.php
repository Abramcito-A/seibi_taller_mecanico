@extends('layouts.app')

@section('title', 'Confirmar nuevo hallazgo')

@section('content')
    <nav aria-label="Ruta de navegación" class="mb-4 flex items-center gap-2 text-xs text-slate-500">
        <a href="{{ route('ordenes.index') }}" class="hover:text-seibi-navy">Órdenes de servicio</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos']) }}" class="hover:text-seibi-navy">{{ $orden->folio }}</a>
        <span aria-hidden="true">/</span>
        <span class="font-medium text-seibi-ink">Confirmar cambios</span>
    </nav>

    <x-ui.page-header title="Nuevo hallazgo pendiente" :description="$orden->folio">
        <x-slot:actions>
            <x-ui.button
                :href="route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos'])"
                variant="neutral"
            >Volver al expediente</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert variant="warning">
        Este formulario ya fue procesado anteriormente. Tus nuevos cambios todavía no se han guardado.
    </x-ui.alert>

    <x-ui.card class="mt-5">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-semibold uppercase text-slate-500">Título nuevo</dt>
                <dd class="mt-1 font-semibold text-seibi-ink">{{ $pending['titulo'] }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-slate-500">Severidad</dt>
                <dd class="mt-1">{{ ucfirst($pending['severidad']) }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-semibold uppercase text-slate-500">Descripción nueva</dt>
                <dd class="mt-1 whitespace-pre-line text-sm text-seibi-ink">{{ $pending['descripcion'] }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-semibold uppercase text-slate-500">Recomendación</dt>
                <dd class="mt-1 whitespace-pre-line text-sm text-seibi-ink">{{ $pending['recomendacion'] ?: '—' }}</dd>
            </div>
        </dl>
        @if (count($pending['photos']) > 0)
            <p class="mt-4 text-sm text-slate-600">{{ count($pending['photos']) }} fotografía(s) técnica(s) nuevas guardadas temporalmente.</p>
        @endif
    </x-ui.card>

    <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <form method="POST" action="{{ route('diagnosticos.discard-changes', [$orden, $token, $diagnostico]) }}">
            @csrf
            <x-ui.button type="submit" variant="neutral">Descartar cambios y ver diagnóstico anterior</x-ui.button>
        </form>
        <form method="POST" action="{{ route('diagnosticos.confirm-new', [$orden, $token]) }}">
            @csrf
            <x-ui.button type="submit" data-submit-diagnosis>Guardar como nuevo hallazgo</x-ui.button>
        </form>
    </div>
@endsection
