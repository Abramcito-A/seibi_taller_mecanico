@props([
    'variant' => 'info',
    'dismissible' => false,
])

@php
    $variants = [
        'success' => ['classes' => 'border-status-success/20 bg-emerald-50 text-status-success', 'icon' => 'circle-check'],
        'error' => ['classes' => 'border-status-error/20 bg-red-50 text-status-error', 'icon' => 'circle-alert'],
        'warning' => ['classes' => 'border-status-pending/20 bg-amber-50 text-status-pending', 'icon' => 'triangle-alert'],
        'info' => ['classes' => 'border-status-info/20 bg-blue-50 text-status-info', 'icon' => 'info'],
    ];
    $alert = $variants[$variant] ?? $variants['info'];
@endphp

<div {{ $attributes->class(['flex items-start gap-3 rounded-lg border px-4 py-3 text-sm', $alert['classes']]) }} role="{{ $variant === 'error' ? 'alert' : 'status' }}" @if ($dismissible) x-data="{ visible: true }" x-show="visible" x-transition.opacity @endif>
    <i data-lucide="{{ $alert['icon'] }}" class="mt-0.5 size-4 shrink-0" aria-hidden="true"></i>
    <div class="min-w-0 flex-1">{{ $slot }}</div>
    @if ($dismissible)<button type="button" class="grid size-6 shrink-0 place-items-center rounded text-current/70 transition hover:bg-black/5 hover:text-current" aria-label="Cerrar aviso" @click="visible = false"><i data-lucide="x" class="size-4" aria-hidden="true"></i></button>@endif
</div>