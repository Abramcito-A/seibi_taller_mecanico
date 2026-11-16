@extends('layouts.app')

@section('title', 'Editar usuario')

@section('content')
    <x-ui.page-header title="Editar usuario" description="Actualiza datos administrativos, rol y estado." />
    <x-ui.card class="mx-auto max-w-2xl">
        <form method="POST" action="{{ route('usuarios.update', $usuario) }}" class="space-y-5">
            @csrf
            @method('PATCH')
            <x-ui.input name="name" label="Nombre" :value="old('name', $usuario->name)" required maxlength="255" />
            <x-ui.input name="email" label="Correo electrónico" type="email" :value="old('email', $usuario->email)" required maxlength="255" />
            <x-ui.input name="password" label="Nueva contraseña (opcional)" type="password" minlength="8" maxlength="255" />
            <x-ui.select name="id_rol" label="Rol" required>
                @foreach ($roles as $role)
                    <option value="{{ $role->id_rol }}" {{ (string) old('id_rol', $usuario->id_rol) === (string) $role->id_rol ? 'selected' : '' }}>{{ ucfirst($role->nombre) }}</option>
                @endforeach
            </x-ui.select>
            <label class="flex items-center gap-2 text-sm text-seibi-ink">
                <input name="activo" type="hidden" value="0">
                <input name="activo" type="checkbox" value="1" {{ old('activo', $usuario->activo) ? 'checked' : '' }} class="rounded border-seibi-border">
                Cuenta activa
            </label>
            <div class="flex justify-end gap-3 border-t border-seibi-border pt-5">
                <x-ui.button :href="route('usuarios.index')" variant="neutral">Cancelar</x-ui.button>
                <x-ui.button type="submit">Guardar cambios</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
