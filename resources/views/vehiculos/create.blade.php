@extends('layouts.app')

@section('title', 'Nuevo Vehículo')

@section('content')
    <x-ui.page-header title="Nuevo vehículo" description="Vincula el vehículo con su cliente y registra la información útil para el servicio.">
        <x-slot:actions><x-ui.back-button :fallback="request('cliente_id') ? route('clientes.show', request('cliente_id')) : route('vehiculos.index')" /></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mx-auto max-w-4xl">
        <form method="POST" action="{{ route('vehiculos.store') }}">
            @csrf
            <div class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.select name="id_cliente" label="Cliente" required>
                        <option value="">Selecciona un cliente</option>
                        @foreach ($clientes as $c)
                            <option value="{{ $c->id_cliente }}" {{ old('id_cliente', request('cliente_id')) == $c->id_cliente ? 'selected' : '' }}>{{ $c->apellido }}, {{ $c->nombre }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.input name="marca" label="Marca" required maxlength="60" />
                <x-ui.input name="modelo" label="Modelo" required maxlength="80" />
                <x-ui.input name="anio" label="Año" type="number" required min="1900" max="{{ date('Y') + 1 }}" />
                <x-ui.input name="version" label="Versión / Trim" maxlength="100" />
                <x-ui.input name="placas" label="Placas" maxlength="15" />
                <x-ui.input name="numero_serie" label="Número de serie (VIN)" maxlength="50" />
                <x-ui.input name="kilometraje_actual" label="Kilometraje actual" type="number" min="0" />
                <div class="sm:col-span-2"><x-ui.textarea name="notas" label="Notas" rows="3" /></div>
            </div>
            <div class="mt-7 flex flex-col-reverse gap-3 border-t border-seibi-border pt-5 sm:flex-row sm:justify-end">
                <x-ui.button :href="route('vehiculos.index')" variant="neutral">Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar vehículo</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
