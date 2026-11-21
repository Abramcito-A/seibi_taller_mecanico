{{-- Resumen de la decisión registrada (solo lectura). Lo usan la vista de resultado y la pestaña «Mi cotización». --}}
@php
    $outcomes = [
        'aprobada' => ['title' => 'Autorizaste todos los conceptos', 'classes' => 'bg-emerald-50 text-status-success', 'icon' => 'circle-check'],
        'parcialmente_aprobada' => ['title' => 'Autorizaste una parte de la cotización', 'classes' => 'bg-amber-50 text-status-pending', 'icon' => 'circle-check'],
        'rechazada' => ['title' => 'No autorizaste ningún concepto', 'classes' => 'bg-red-50 text-status-error', 'icon' => 'circle-alert'],
    ];
    $outcome = $outcomes[$cotizacion->estado] ?? $outcomes['rechazada'];
    $necessaryRejected = $detalles->filter(fn ($detail) => $detail->isNecessary() && $detail->decision_cliente === 'rechazado');
@endphp

<section class="rounded-panel border border-seibi-border bg-white p-5 text-center shadow-panel">
    <span class="mx-auto grid size-12 place-items-center rounded-full {{ $outcome['classes'] }}" aria-hidden="true"><i data-lucide="{{ $outcome['icon'] }}" class="size-6"></i></span>
    <h1 class="mt-3 text-xl font-semibold text-seibi-ink">{{ $outcome['title'] }}</h1>
    <p class="mt-1 text-sm text-slate-600">Cotización {{ $cotizacion->folio }} · versión {{ $cotizacion->version }}</p>
    <dl class="mx-auto mt-4 grid max-w-sm grid-cols-2 gap-3 border-t border-seibi-border pt-4 text-left text-sm">
        <div><dt class="text-xs text-slate-500">Fecha de decisión</dt><dd class="font-medium">{{ \App\Models\Cotizacion::formatForDisplay($cotizacion->fecha_decision, 'j M Y, H:i') }}</dd></div>
        <div><dt class="text-xs text-slate-500">Orden de servicio</dt><dd class="font-medium break-all">{{ $ordenFolio }}</dd></div>
        <div><dt class="text-xs text-slate-500">Importe autorizado</dt><dd class="text-lg font-bold text-seibi-ink">$ {{ number_format((float) $cotizacion->importe_autorizado, 2, '.', ',') }}</dd></div>
        <div><dt class="text-xs text-slate-500">Total cotizado</dt><dd class="font-medium">$ {{ number_format((float) $cotizacion->total, 2, '.', ',') }}</dd></div>
    </dl>
    <p class="mt-3 text-xs text-slate-500">Importes en MXN. Horario de Monterrey.</p>
</section>

@if ($necessaryRejected->isNotEmpty())
    <x-ui.alert variant="warning">
        <p class="font-semibold">Rechazaste conceptos clasificados como necesarios</p>
        <p class="mt-1 leading-6">El taller revisará tu decisión y te indicará cómo continuar. Esto no significa que el vehículo esté en condiciones seguras.</p>
    </x-ui.alert>
@endif

<section class="space-y-3">
    <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-slate-500">Detalle de tu decisión</h2>
    @foreach ($detalles as $detail)
        <article class="rounded-panel border border-seibi-border bg-white p-4 shadow-panel">
            @include('portal.cotizaciones._concepto-info', ['detail' => $detail])
            <p class="mt-3 border-t border-seibi-border pt-3 text-sm font-semibold {{ $detail->decision_cliente === 'aceptado' ? 'text-status-success' : 'text-status-error' }}">
                {{ $detail->decision_cliente === 'aceptado' ? 'Autorizado' : 'Rechazado' }}
                @if ($detail->riesgo_confirmado_en)
                    <span class="block text-xs font-normal text-slate-500">Advertencia confirmada el {{ \App\Models\Cotizacion::formatForDisplay($detail->riesgo_confirmado_en, 'j M Y, H:i') }}</span>
                @endif
            </p>
        </article>
    @endforeach
</section>

<p class="px-1 text-xs leading-5 text-slate-500">Tu decisión quedó registrada y no puede modificarse desde este enlace. Si necesitas cambiarla, comunícate con el taller para que prepare una nueva versión de la cotización.</p>
