<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>403 · Acceso no autorizado · SEIBI</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-seibi-workspace text-seibi-ink antialiased">
    @php
        $fallbackUrl = auth()->check() ? route('dashboard') : route('login');
    @endphp

    <main class="relative isolate flex min-h-screen items-center justify-center overflow-hidden px-4 py-10 sm:px-6">
        <div class="pointer-events-none absolute inset-0 -z-10 bg-[linear-gradient(to_right,rgb(23_43_64_/_3%)_1px,transparent_1px),linear-gradient(to_bottom,rgb(23_43_64_/_3%)_1px,transparent_1px)] bg-[size:56px_56px]" aria-hidden="true"></div>
        <div class="pointer-events-none absolute left-0 top-[22%] h-px w-[15vw] bg-seibi-navy/15" aria-hidden="true"></div>
        <div class="pointer-events-none absolute bottom-[20%] right-0 h-px w-[15vw] bg-seibi-navy/15" aria-hidden="true"></div>

        <section class="w-full max-w-2xl text-center" data-motion="enter" aria-labelledby="error-title">
            <a href="{{ $fallbackUrl }}" class="mx-auto mb-8 inline-flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-seibi-navy" aria-label="Volver a SEIBI">
                <span class="grid size-11 place-items-center rounded-xl bg-seibi-navy text-white shadow-sm"><i data-lucide="wrench" class="size-5" aria-hidden="true"></i></span>
                <span class="text-left">
                    <span class="block text-lg font-bold leading-tight text-seibi-ink">SEIBI</span>
                    <span class="mt-1 block text-[0.6875rem] font-semibold uppercase text-slate-500">Gestión de taller</span>
                </span>
            </a>

            <x-ui.card class="px-6 py-8 sm:px-12 sm:py-11">
                <div class="mx-auto flex size-36 items-center justify-center" role="img" aria-label="Vehículo protegido: acceso restringido">
                    <div class="relative grid size-32 place-items-center rounded-full border border-seibi-border bg-seibi-workspace">
                        <div class="grid size-20 place-items-center rounded-2xl border border-seibi-border bg-white text-seibi-navy shadow-sm">
                            <i data-lucide="car-front" class="size-11" aria-hidden="true"></i>
                        </div>
                        <span class="absolute -bottom-1 -right-1 grid size-12 place-items-center rounded-xl border-4 border-white bg-seibi-navy text-white shadow-panel">
                            <i data-lucide="lock-keyhole" class="size-5" aria-hidden="true"></i>
                        </span>
                        <span class="absolute -left-2 top-4 size-3 rounded-sm border border-seibi-border bg-white" aria-hidden="true"></span>
                        <span class="absolute -right-2 bottom-7 size-2 rounded-full bg-status-error" aria-hidden="true"></span>
                    </div>
                </div>

                <div class="mt-6">
                    <span class="inline-flex items-center gap-2 rounded-full border border-status-error/20 bg-red-50 px-3 py-1 text-xs font-bold text-status-error">
                        <span class="size-1.5 rounded-full bg-status-error" aria-hidden="true"></span>
                        Error 403
                    </span>
                    <h1 id="error-title" class="mt-4 text-3xl font-bold leading-tight text-seibi-ink sm:text-4xl">Acceso no autorizado</h1>
                    <p class="mx-auto mt-3 max-w-lg text-sm leading-6 text-slate-600 sm:text-base">
                        Esta sección está fuera de los permisos de tu perfil. Regresa a tu panel para continuar trabajando en SEIBI.
                    </p>
                </div>

                <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                    <x-ui.button :href="$fallbackUrl" icon="arrow-right">{{ auth()->check() ? 'Regresar al panel' : 'Iniciar sesión' }}</x-ui.button>
                    <x-ui.button :href="$fallbackUrl" variant="neutral" icon="arrow-left" @click="if (window.history.length > 1 && document.referrer && new URL(document.referrer).origin === window.location.origin) { $event.preventDefault(); window.history.back(); }">Volver atrás</x-ui.button>
                </div>
            </x-ui.card>

            <p class="mt-6 text-xs text-slate-500">Si necesitas acceso a esta sección, solicita ayuda al administrador del taller.</p>
        </section>
    </main>
</body>
</html>