@extends('layouts.app')

@section('title', 'Nuevo Vehículo')

@section('content')
    @php
        // A dónde regresan «Volver» y «Cancelar»: la pantalla desde la que se abrió el formulario.
        $returnUrl = match (true) {
            $origen === 'recepcion' && $lockedClient !== null => route('ordenes.create', ['cliente_id' => $lockedClient->id_cliente]),
            $lockedClient !== null => route('clientes.show', $lockedClient),
            default => route('vehiculos.index'),
        };
    @endphp

    <x-ui.page-header title="Nuevo vehículo" description="Vincula el vehículo con su cliente y registra la información útil para el servicio.">
        <x-slot:actions><x-ui.back-button :fallback="$returnUrl" /></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mx-auto max-w-4xl">
        <form method="POST" action="{{ route('vehiculos.store') }}">
            @csrf
            @if ($origen)<input type="hidden" name="origen" value="{{ $origen }}">@endif
            <div class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    @if ($lockedClient)
                        {{-- Cliente fijo: viene del perfil del cliente o de la recepción. --}}
                        <input type="hidden" name="id_cliente" value="{{ $lockedClient->id_cliente }}">
                        <p class="block text-sm font-semibold text-seibi-ink">Cliente</p>
                        <div class="mt-1.5 flex items-center gap-3 rounded-lg border border-seibi-border bg-seibi-workspace px-3 py-2.5 text-sm">
                            <i data-lucide="lock" class="size-4 shrink-0 text-slate-500" aria-hidden="true"></i>
                            <span class="font-semibold text-seibi-ink">{{ $lockedClient->nombre }} {{ $lockedClient->apellido }}</span>
                            @if ($lockedClient->telefono)<span class="text-slate-500">· {{ $lockedClient->telefono }}</span>@endif
                        </div>
                        @error('id_cliente')<p class="mt-1.5 text-xs font-medium text-status-error" role="alert">{{ $message }}</p>@enderror
                    @else
                        <x-ui.select name="id_cliente" label="Cliente" required>
                            <option value="">Selecciona un cliente</option>
                            @foreach ($clientes as $c)
                                <option value="{{ $c->id_cliente }}" {{ old('id_cliente') == $c->id_cliente ? 'selected' : '' }}>{{ $c->apellido }}, {{ $c->nombre }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                </div>
                <x-ui.input name="marca" label="Marca" required maxlength="60" />
                <x-ui.input name="modelo" label="Modelo" required maxlength="80" />
                <x-ui.select name="anio" label="Año" required>
                    <option value="">Selecciona el año</option>
                    @foreach ($years as $year)
                        <option value="{{ $year }}" @selected((string) old('anio') === (string) $year)>{{ $year }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="version" label="Versión / Trim" maxlength="100" />
                <x-ui.input name="placas" label="Placas" maxlength="15" />
                <x-ui.input name="numero_serie" label="Número de serie (VIN)" maxlength="50" />
                <x-ui.input name="kilometraje_actual" label="Kilometraje actual" type="number" min="0" />
            </div>
            <div class="mt-7 flex flex-col-reverse gap-3 border-t border-seibi-border pt-5 sm:flex-row sm:justify-end">
                <x-ui.button :href="$returnUrl" variant="neutral">Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar vehículo</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
