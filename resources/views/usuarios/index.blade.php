@extends('layouts.app')

@section('title', 'Gestión de usuarios')

@section('content')
    <x-ui.page-header title="Gestión de usuarios" description="Administra cuentas internas y roles del taller.">
        <x-slot:actions>
            <x-ui.button :href="route('usuarios.create')" icon="plus">Nuevo usuario</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel title="Usuarios" description="Cuentas con acceso al sistema" padding="p-0">
        <form method="GET" action="{{ route('usuarios.index') }}" class="flex gap-3 border-b border-seibi-border p-4">
            <x-ui.input name="q" label="Buscar por nombre o correo" :value="$search" placeholder="Nombre o correo" />
            <div class="flex items-end"><x-ui.button type="submit" variant="secondary">Buscar</x-ui.button></div>
        </form>
        @if ($users->isEmpty())
            <x-ui.empty-state title="No se encontraron usuarios" description="Prueba otra búsqueda o registra una cuenta nueva." icon="users" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-seibi-border">
                    <thead class="bg-seibi-workspace">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Nombre</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Correo</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Rol</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Estado</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-seibi-border bg-white">
                        @foreach ($users as $user)
                            <tr>
                                <td class="px-4 py-3 text-sm font-semibold text-seibi-ink">{{ $user->name }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600">{{ $user->email }}</td>
                                <td class="px-4 py-3 text-sm text-slate-600">{{ ucfirst($user->role?->nombre ?? 'Sin rol') }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :tone="$user->activo ? 'success' : 'neutral'">{{ $user->activo ? 'Activo' : 'Inactivo' }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('usuarios.edit', $user) }}" class="text-sm font-semibold text-seibi-navy hover:underline">Editar</a>
                                        <form method="POST" action="{{ route('usuarios.toggle-active', $user) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="text-sm font-semibold {{ $user->activo ? 'text-status-error' : 'text-status-success' }}">
                                                {{ $user->activo ? 'Desactivar' : 'Activar' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($users->hasPages())
            <div class="border-t border-seibi-border p-4">{{ $users->links() }}</div>
        @endif
    </x-ui.panel>
@endsection
