@extends('layouts.app')

@section('title', 'Nuevo Cliente')

@section('content')
    <x-ui.page-header title="Nuevo cliente" description="Registra los datos de contacto para asociarlos a sus vehículos y órdenes de servicio.">
        <x-slot:actions><x-ui.back-button :fallback="route('clientes.index')" /></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mx-auto max-w-3xl">
        <form method="POST" action="{{ route('clientes.store') }}">
            @csrf
            <div class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                <x-ui.input name="nombre" label="Nombre" required maxlength="100" autocomplete="given-name" />
                <x-ui.input name="apellido" label="Apellido" required maxlength="100" autocomplete="family-name" />
                <x-ui.input name="telefono" label="Teléfono" required maxlength="20" autocomplete="tel" />
                <x-ui.input name="correo" label="Correo electrónico" type="email" maxlength="150" autocomplete="email" />
                <div class="sm:col-span-2"><x-ui.input name="direccion" label="Dirección" maxlength="255" autocomplete="street-address" /></div>
            </div>
            <div class="mt-7 flex flex-col-reverse gap-3 border-t border-seibi-border pt-5 sm:flex-row sm:justify-end">
                <x-ui.button :href="route('clientes.index')" variant="neutral">Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar cliente</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
