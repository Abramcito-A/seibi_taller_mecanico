@extends('layouts.app')

@section('title', 'Editar Cliente')

@section('content')
    <x-ui.page-header title="Editar cliente" :description="$cliente->nombre . ' ' . $cliente->apellido">
        <x-slot:actions><x-ui.back-button :fallback="route('clientes.show', $cliente)" /></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mx-auto max-w-3xl">
        <form method="POST" action="{{ route('clientes.update', $cliente) }}">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                <x-ui.input name="nombre" label="Nombre" :value="$cliente->nombre" required maxlength="100" autocomplete="given-name" />
                <x-ui.input name="apellido" label="Apellido" :value="$cliente->apellido" required maxlength="100" autocomplete="family-name" />
                <x-ui.input name="telefono" label="Teléfono" :value="$cliente->telefono" required maxlength="20" autocomplete="tel" />
                <x-ui.input name="correo" label="Correo electrónico" type="email" :value="$cliente->correo" maxlength="150" autocomplete="email" />
                <div class="sm:col-span-2"><x-ui.input name="direccion" label="Dirección" :value="$cliente->direccion" maxlength="255" autocomplete="street-address" /></div>
            </div>
            <div class="mt-7 flex flex-col-reverse gap-3 border-t border-seibi-border pt-5 sm:flex-row sm:justify-end">
                <x-ui.button :href="route('clientes.show', $cliente)" variant="neutral">Cancelar</x-ui.button>
                <x-ui.button type="submit">Actualizar cliente</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
