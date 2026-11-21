<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <meta name="format-detection" content="telephone=no">
    <title>@yield('title', 'Cotización') · {{ config('seibi.workshop_name') }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-seibi-workspace text-seibi-ink antialiased">
    <header class="bg-seibi-navy text-white">
        <div class="mx-auto flex w-full max-w-2xl items-center gap-3 px-4 py-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-seibi-orange text-lg font-bold" aria-hidden="true">S</span>
            <div class="min-w-0">
                <p class="truncate text-base font-semibold">{{ config('seibi.workshop_name') }}</p>
                <p class="text-xs text-white/70">@yield('subtitle', 'Consulta de cotización')</p>
            </div>
        </div>
        <div class="h-1 w-full bg-seibi-orange" aria-hidden="true"></div>
    </header>

    <main class="mx-auto w-full max-w-2xl px-4 py-6 sm:py-8">
        @yield('content')
    </main>

    <footer class="mx-auto w-full max-w-2xl px-4 pb-10 text-xs leading-5 text-slate-500">
        <p>{{ config('seibi.workshop_name') }} · Este enlace es personal; no lo compartas.</p>
        <p>Si tienes dudas sobre algún concepto, comunícate con el taller antes de decidir.</p>
    </footer>
</body>
</html>
