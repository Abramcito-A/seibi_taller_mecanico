@extends('layouts.app')

@section('title', 'Editar Vehículo')

@section('content')
    <x-ui.page-header title="Editar vehículo" :description="$vehiculo->marca . ' ' . $vehiculo->modelo . ' ' . $vehiculo->anio">
        <x-slot:actions><x-ui.back-button :fallback="route('vehiculos.show', $vehiculo)" /></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mx-auto max-w-4xl">
        <form method="POST" action="{{ route('vehiculos.update', $vehiculo) }}">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.select name="id_cliente" label="Cliente" required>
                        <option value="">Selecciona un cliente</option>
                        @foreach ($clientes as $c)
                            <option value="{{ $c->id_cliente }}" {{ old('id_cliente', $vehiculo->id_cliente) == $c->id_cliente ? 'selected' : '' }}>{{ $c->apellido }}, {{ $c->nombre }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.input name="marca" label="Marca" :value="$vehiculo->marca" required maxlength="60" />
                <x-ui.input name="modelo" label="Modelo" :value="$vehiculo->modelo" required maxlength="80" />
                <x-ui.input name="anio" label="Año" type="number" :value="$vehiculo->anio" required min="1900" max="{{ date('Y') + 1 }}" />
                <x-ui.input name="version" label="Versión / Trim" :value="$vehiculo->version" maxlength="100" />
                <x-ui.input name="placas" label="Placas" :value="$vehiculo->placas" maxlength="15" />
                <x-ui.input name="numero_serie" label="Número de serie (VIN)" :value="$vehiculo->numero_serie" maxlength="50" />
                <x-ui.input name="kilometraje_actual" label="Kilometraje actual" type="number" :value="$vehiculo->kilometraje_actual" min="0" />
                <div class="sm:col-span-2"><x-ui.textarea name="notas" label="Notas" :value="$vehiculo->notas" rows="3" /></div>
            </div>
            <div class="mt-7 flex flex-col-reverse gap-3 border-t border-seibi-border pt-5 sm:flex-row sm:justify-end">
                <x-ui.button :href="route('vehiculos.show', $vehiculo)" variant="neutral">Cancelar</x-ui.button>
                <x-ui.button type="submit">Actualizar vehículo</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
