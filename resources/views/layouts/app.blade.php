<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SEIBI · @yield('title', 'Panel')</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-seibi-workspace text-seibi-ink antialiased" x-data="{ mobileNavOpen: false }" @keydown.escape.window="mobileNavOpen = false">
    @php
        $roleLabel = match (request()->user()->role?->nombre) {
            'administrador' => 'Administrador',
            'mecanico' => 'Mecánico',
            'recepcionista' => 'Recepcionista',
            default => 'Personal',
        };
    @endphp
    <div class="min-h-screen lg:flex">
        <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col bg-seibi-navy text-white lg:flex">
            <a href="{{ route('dashboard') }}" class="flex h-20 items-center gap-3 border-b border-white/10 px-6" aria-label="SEIBI, inicio">
                <span class="grid size-10 place-items-center rounded-lg border border-white/15 bg-white/10">
                    <i data-lucide="wrench" class="size-5" aria-hidden="true"></i>
                </span>
                <span>
                    <span class="block text-lg font-bold leading-tight">SEIBI</span>
                    <span class="mt-1 block text-[0.6875rem] font-medium uppercase text-white/55">Gestión de taller</span>
                </span>
            </a>

            <nav class="flex-1 space-y-7 overflow-y-auto px-3 py-7" aria-label="Navegación principal">
                <div>
                    <p class="px-3 pb-2 text-[0.6875rem] font-semibold uppercase text-white/45">Operación</p>
                    <div class="space-y-1">
                        <x-ui.nav-link href="{{ route('dashboard') }}" icon="layout-dashboard" :active="request()->routeIs('dashboard')">Panel</x-ui.nav-link>
                        <div x-data="{ ordersOpen: {{ request()->routeIs('ordenes.*') ? 'true' : 'false' }} }">
                            <button type="button"
                                class="group flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition duration-200 ease-ui focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                :class="ordersOpen || {{ request()->routeIs('ordenes.*') ? 'true' : 'false' }} ? 'bg-white/12 text-white shadow-sm' : 'text-white/70 hover:bg-white/8 hover:text-white'"
                                aria-controls="desktop-orders-navigation"
                                :aria-expanded="ordersOpen.toString()"
                                @click="ordersOpen = ! ordersOpen">
                                <i data-lucide="clipboard-list" class="size-[1.125rem] shrink-0 transition-transform duration-200 group-hover:scale-105" aria-hidden="true"></i>
                                <span class="flex-1">Órdenes de servicio</span>
                                <i data-lucide="chevron-down" class="size-4 shrink-0 transition-transform duration-200" :class="{ 'rotate-180': ordersOpen }" aria-hidden="true"></i>
                            </button>
                            <div id="desktop-orders-navigation" x-show="ordersOpen" x-collapse x-cloak class="mt-1 space-y-1 border-l border-white/10 py-1 pl-3 ml-3">
                                <x-ui.nav-link href="{{ route('ordenes.index') }}" icon="list" :active="request()->routeIs('ordenes.index', 'ordenes.show', 'ordenes.edit')" class="min-h-9 px-2 py-1.5 text-xs">Todas las órdenes</x-ui.nav-link>
                                @if (request()->user()->hasRole('administrador', 'recepcionista'))
                                    <x-ui.nav-link href="{{ route('ordenes.create') }}" icon="plus" :active="request()->routeIs('ordenes.create')" class="min-h-9 px-2 py-1.5 text-xs">Nueva recepción</x-ui.nav-link>
                                @endif
                            </div>
                        </div>
                        <x-ui.nav-link href="{{ route('clientes.index') }}" icon="users" :active="request()->routeIs('clientes.*')">Clientes</x-ui.nav-link>
                        <x-ui.nav-link href="{{ route('vehiculos.index') }}" icon="car-front" :active="request()->routeIs('vehiculos.*')">Vehículos</x-ui.nav-link>
                    </div>
                </div>
            </nav>

            <div class="border-t border-white/10 px-6 py-5 text-xs text-white/55">SEIBI</div>
        </aside>

        <div class="min-w-0 flex-1">
            <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-seibi-border bg-white/95 px-4 backdrop-blur-sm sm:px-6 lg:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" class="grid size-10 shrink-0 place-items-center rounded-lg border border-seibi-border text-seibi-ink transition hover:bg-seibi-workspace focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-seibi-navy lg:hidden" aria-label="Abrir navegación" aria-controls="mobile-navigation" :aria-expanded="mobileNavOpen.toString()" @click="mobileNavOpen = true">
                        <i data-lucide="menu" class="size-5" aria-hidden="true"></i>
                    </button>
                    <div class="min-w-0">
                        <p class="truncate text-xs font-medium text-slate-500">SEIBI <span class="px-1 text-slate-300">/</span> Administración</p>
                        <p class="truncate text-sm font-semibold text-seibi-ink">@yield('title', 'Panel')</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-semibold text-seibi-ink">{{ request()->user()->name }}</p>
                        <p class="text-xs text-slate-500">{{ $roleLabel }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-ui.button type="submit" variant="neutral" icon="log-out" class="px-3" aria-label="Cerrar sesión">Salir</x-ui.button>
                    </form>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 sm:py-8 lg:px-8" data-motion="enter">
                <section class="mb-6 space-y-3" aria-label="Notificaciones" aria-live="polite">
                    @if (session('success'))
                        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
                        @endif
                        @if (session('info'))
                        <x-ui.alert variant="info" :dismissible="true">
                            {{ session('info') }}
                            @if (session('duplicate_diagnosis_url'))
                                <a href="{{ session('duplicate_diagnosis_url') }}" class="ml-1 font-semibold underline underline-offset-2">Ver diagnóstico existente</a>
                            @endif
                        </x-ui.alert>
                    @endif
                    @if (session('error'))
                        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
                    @endif
                    @if ($errors->any())
                        <x-ui.alert variant="error">Revisa los campos marcados para continuar.</x-ui.alert>
                    @endif
                </section>

                @yield('content')

                <footer class="mt-10 border-t border-seibi-border pt-5 text-xs text-slate-500">
                    &copy; {{ date('Y') }} SEIBI · Gestión de taller
                </footer>
            </main>
        </div>
    </div>

    <div x-cloak x-show="mobileNavOpen" x-transition.opacity class="fixed inset-0 z-50 lg:hidden" role="presentation">
        <button type="button" class="absolute inset-0 bg-seibi-navy/45" aria-label="Cerrar navegación" @click="mobileNavOpen = false"></button>
        <aside id="mobile-navigation" x-show="mobileNavOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-4 opacity-0" x-transition:enter-end="translate-x-0 opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0 opacity-100" x-transition:leave-end="-translate-x-4 opacity-0" x-trap.noscroll="mobileNavOpen" class="relative flex h-full w-[min(19rem,88vw)] flex-col bg-seibi-navy text-white shadow-2xl" aria-label="Navegación móvil">
            <div class="flex h-20 items-center justify-between border-b border-white/10 px-6">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3" @click="mobileNavOpen = false">
                    <span class="grid size-10 place-items-center rounded-lg border border-white/15 bg-white/10"><i data-lucide="wrench" class="size-5" aria-hidden="true"></i></span>
                    <span><span class="block text-lg font-bold">SEIBI</span><span class="block text-[0.6875rem] uppercase text-white/55">Gestión de taller</span></span>
                </a>
                <button type="button" class="grid size-10 place-items-center rounded-lg text-white/75 transition hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white" aria-label="Cerrar navegación" @click="mobileNavOpen = false"><i data-lucide="x" class="size-5" aria-hidden="true"></i></button>
            </div>
            <nav class="flex-1 space-y-7 px-3 py-7" aria-label="Navegación principal móvil">
                <div>
                    <p class="px-3 pb-2 text-[0.6875rem] font-semibold uppercase text-white/45">Operación</p>
                    <div class="space-y-1">
                        <x-ui.nav-link href="{{ route('dashboard') }}" icon="layout-dashboard" :active="request()->routeIs('dashboard')" @click="mobileNavOpen = false">Panel</x-ui.nav-link>
                        <div x-data="{ ordersOpen: {{ request()->routeIs('ordenes.*') ? 'true' : 'false' }} }">
                            <button type="button"
                                class="group flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium transition duration-200 ease-ui focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                :class="ordersOpen || {{ request()->routeIs('ordenes.*') ? 'true' : 'false' }} ? 'bg-white/12 text-white shadow-sm' : 'text-white/70 hover:bg-white/8 hover:text-white'"
                                aria-controls="mobile-orders-navigation"
                                :aria-expanded="ordersOpen.toString()"
                                @click="ordersOpen = ! ordersOpen">
                                <i data-lucide="clipboard-list" class="size-[1.125rem] shrink-0 transition-transform duration-200 group-hover:scale-105" aria-hidden="true"></i>
                                <span class="flex-1">Órdenes de servicio</span>
                                <i data-lucide="chevron-down" class="size-4 shrink-0 transition-transform duration-200" :class="{ 'rotate-180': ordersOpen }" aria-hidden="true"></i>
                            </button>
                            <div id="mobile-orders-navigation" x-show="ordersOpen" x-collapse x-cloak class="mt-1 space-y-1 border-l border-white/10 py-1 pl-3 ml-3">
                                <x-ui.nav-link href="{{ route('ordenes.index') }}" icon="list" :active="request()->routeIs('ordenes.index', 'ordenes.show', 'ordenes.edit')" class="min-h-9 px-2 py-1.5 text-xs" @click="mobileNavOpen = false">Todas las órdenes</x-ui.nav-link>
                                @if (request()->user()->hasRole('administrador', 'recepcionista'))
                                    <x-ui.nav-link href="{{ route('ordenes.create') }}" icon="plus" :active="request()->routeIs('ordenes.create')" class="min-h-9 px-2 py-1.5 text-xs" @click="mobileNavOpen = false">Nueva recepción</x-ui.nav-link>
                                @endif
                            </div>
                        </div>
                        <x-ui.nav-link href="{{ route('clientes.index') }}" icon="users" :active="request()->routeIs('clientes.*')" @click="mobileNavOpen = false">Clientes</x-ui.nav-link>
                        <x-ui.nav-link href="{{ route('vehiculos.index') }}" icon="car-front" :active="request()->routeIs('vehiculos.*')" @click="mobileNavOpen = false">Vehículos</x-ui.nav-link>
                    </div>
                </div>
            </nav>
            <div class="border-t border-white/10 px-6 py-5 text-xs text-white/55">SEIBI</div>
        </aside>
    </div>

    <x-ui.confirm-dialog />
</body>
</html>
