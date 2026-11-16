<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Acceso interno · SEIBI</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-seibi-workspace text-seibi-ink antialiased" x-data="{ showPassword: false, submitting: false }">
    <main class="relative isolate flex min-h-screen items-center justify-center overflow-hidden px-4 py-8 sm:px-6 sm:py-12">
        <div class="pointer-events-none absolute inset-0 -z-10 bg-[linear-gradient(to_right,rgb(23_43_64_/_3%)_1px,transparent_1px),linear-gradient(to_bottom,rgb(23_43_64_/_3%)_1px,transparent_1px)] bg-[size:56px_56px]" aria-hidden="true"></div>
        <div class="pointer-events-none absolute left-[8%] top-[14%] hidden size-36 rotate-12 border border-seibi-navy/10 sm:block" aria-hidden="true"></div>
        <div class="pointer-events-none absolute bottom-[12%] right-[8%] hidden size-44 -rotate-12 border border-seibi-navy/10 sm:block" aria-hidden="true"></div>
        <div class="pointer-events-none absolute left-0 top-1/3 h-px w-[12vw] bg-seibi-orange/35" aria-hidden="true"></div>
        <div class="pointer-events-none absolute bottom-1/3 right-0 h-px w-[12vw] bg-seibi-navy/20" aria-hidden="true"></div>

        <section class="w-full max-w-[28rem]" data-motion="enter" aria-labelledby="login-title">
            @if (session('error'))
                <x-ui.alert variant="error" class="mb-4">{{ session('error') }}</x-ui.alert>
            @endif
            @error('login')
                <x-ui.alert variant="error" class="mb-4">{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.card padding="p-0" class="overflow-hidden">
                <div class="h-1 w-full bg-seibi-navy" aria-hidden="true">
                    <div class="mx-auto h-full w-16 bg-seibi-orange"></div>
                </div>

                <div class="px-6 pb-7 pt-8 sm:px-9 sm:pb-9 sm:pt-10">
                    <header class="mb-8 text-center">
                        <a href="{{ route('login') }}" class="mx-auto inline-flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-seibi-navy" aria-label="SEIBI">
                            <span class="relative grid size-12 place-items-center rounded-xl bg-seibi-navy text-white shadow-sm">
                                <i data-lucide="wrench" class="size-5" aria-hidden="true"></i>
                                <span class="absolute -bottom-1 -right-1 size-3 rounded-sm border-2 border-white bg-seibi-orange" aria-hidden="true"></span>
                            </span>
                            <span class="text-left">
                                <span class="block text-xl font-bold leading-tight text-seibi-ink">SEIBI</span>
                                <span class="mt-1 block text-[0.6875rem] font-semibold uppercase text-slate-500">Gestión de taller</span>
                            </span>
                        </a>

                        <div class="mt-8">
                            <p class="text-xs font-semibold uppercase text-seibi-navy">Acceso interno</p>
                            <h1 id="login-title" class="mt-2 text-3xl font-bold leading-tight text-seibi-ink">Bienvenido de nuevo</h1>
                            <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-slate-600">Inicia sesión con tu cuenta. Este espacio está reservado al personal autorizado.</p>
                        </div>
                    </header>

                    <form method="POST" action="{{ route('login.store') }}" class="space-y-5" @submit="submitting = true">
                        @csrf
                        <div class="space-y-1.5">
                            <label for="email" class="block text-sm font-semibold text-seibi-ink">Correo electrónico</label>
                            <div class="relative">
                                <i data-lucide="mail" class="pointer-events-none absolute left-3.5 top-1/2 size-[1.125rem] -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" inputmode="email" required autofocus aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @if ($errors->has('email')) aria-describedby="email-error" @endif class="ui-input pl-11">
                            </div>
                            @error('email')<p id="email-error" class="text-xs font-medium text-status-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="space-y-1.5">
                            <label for="password" class="block text-sm font-semibold text-seibi-ink">Contraseña</label>
                            <div class="relative">
                                <i data-lucide="lock-keyhole" class="pointer-events-none absolute left-3.5 top-1/2 size-[1.125rem] -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                                <input id="password" name="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" @if ($errors->has('password')) aria-describedby="password-error" @endif class="ui-input pl-11 pr-24">
                                <button type="button" class="absolute inset-y-1 right-1 inline-flex min-w-20 items-center justify-center gap-1.5 rounded-md px-2 text-xs font-semibold text-seibi-navy transition duration-200 hover:bg-seibi-workspace focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-seibi-navy active:bg-seibi-border" :aria-pressed="showPassword.toString()" :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'" @click="showPassword = !showPassword">
                                    <i x-show="!showPassword" x-transition.opacity.duration.150ms data-lucide="eye" class="size-4" aria-hidden="true"></i>
                                    <i x-cloak x-show="showPassword" x-transition.opacity.duration.150ms data-lucide="eye-off" class="size-4" aria-hidden="true"></i>
                                    <span x-text="showPassword ? 'Ocultar' : 'Mostrar'">Mostrar</span>
                                </button>
                            </div>
                            @error('password')<p id="password-error" class="text-xs font-medium text-status-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <x-ui.button type="submit" class="w-full" ::disabled="submitting">
                            <span x-cloak x-show="submitting" class="size-4 animate-spin rounded-full border-2 border-current border-r-transparent" aria-hidden="true"></span>
                            <span x-show="!submitting" aria-hidden="true"><i data-lucide="arrow-right" class="size-4" aria-hidden="true"></i></span>
                            <span x-text="submitting ? 'Verificando…' : 'Iniciar sesión'">Iniciar sesión</span>
                        </x-ui.button>
                    </form>

                    <div class="mt-7 flex items-center justify-center gap-2 border-t border-seibi-border pt-5 text-xs text-slate-500">
                        <i data-lucide="shield-check" class="size-4 text-seibi-navy" aria-hidden="true"></i>
                        <span>Acceso exclusivo para el equipo SEIBI</span>
                    </div>
                </div>
            </x-ui.card>

            <p class="mt-5 px-3 text-center text-xs leading-5 text-slate-500">¿Necesitas una cuenta? Contacta al administrador del taller.</p>
            <p class="mt-7 text-center text-[0.6875rem] text-slate-400">SEIBI <span class="px-1 text-slate-300">·</span> Servicio automotriz</p>
        </section>
    </main>
</body>
</html>