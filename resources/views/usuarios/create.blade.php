@extends('layouts.app')

@section('title', 'Nuevo usuario')

@section('content')
    <x-ui.page-header title="Nuevo usuario" description="Registra una cuenta interna y asigna su rol." />
    <x-ui.card class="mx-auto max-w-2xl">
        <form method="POST" action="{{ route('usuarios.store') }}" class="space-y-5">
            @csrf
            <x-ui.input name="name" label="Nombre" :value="old('name')" required maxlength="255" />
            <x-ui.input name="email" label="Correo electrónico" type="email" :value="old('email')" required maxlength="255" />
            <x-ui.input name="password" label="Contraseña nueva" type="password" required minlength="8" maxlength="255" />
            <x-ui.select name="id_rol" label="Rol" required>
                @foreach ($roles as $role)
                    <option value="{{ $role->id_rol }}" {{ (string) old('id_rol') === (string) $role->id_rol ? 'selected' : '' }}>{{ ucfirst($role->nombre) }}</option>
                @endforeach
            </x-ui.select>
            <label class="flex items-center gap-2 text-sm text-seibi-ink">
                <input name="activo" type="checkbox" value="1" checked class="rounded border-seibi-border">
                Cuenta activa
            </label>
            <div class="flex justify-end gap-3 border-t border-seibi-border pt-5">
                <x-ui.button :href="route('usuarios.index')" variant="neutral">Cancelar</x-ui.button>
                <x-ui.button type="submit" icon="user-plus">Registrar usuario</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
