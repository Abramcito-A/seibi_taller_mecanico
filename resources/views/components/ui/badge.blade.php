@props([
    'tone' => 'neutral',
])

@php
    $tones = [
        'neutral' => 'border-seibi-border bg-seibi-workspace text-seibi-ink',
        'success' => 'border-status-success/20 bg-emerald-50 text-status-success',
        'error' => 'border-status-error/20 bg-red-50 text-status-error',
        'info' => 'border-status-info/20 bg-blue-50 text-status-info',
        'pending' => 'border-status-pending/20 bg-amber-50 text-status-pending',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold', $tones[$tone] ?? $tones['neutral']]) }}>
    {{ $slot }}
</span>