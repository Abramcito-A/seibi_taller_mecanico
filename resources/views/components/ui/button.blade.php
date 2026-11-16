@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'disabled' => false,
    'size' => 'default',
])

@php
    $variants = [
        'primary' => 'bg-seibi-orange text-white hover:bg-[#A94312] focus-visible:outline-seibi-orange',
        'secondary' => 'border border-seibi-navy/20 bg-white text-seibi-navy hover:bg-seibi-workspace focus-visible:outline-seibi-navy',
        'neutral' => 'border border-seibi-border bg-white text-seibi-ink hover:bg-seibi-workspace focus-visible:outline-seibi-navy',
        'destructive' => 'border border-status-error/20 bg-status-error text-white hover:bg-[#921D16] focus-visible:outline-status-error',
        'destructive-link' => 'min-h-0 border-0 bg-transparent px-0 py-0 text-status-error shadow-none hover:bg-transparent hover:underline focus-visible:outline-status-error',
    ];
    $variantClasses = $variants[$variant] ?? $variants['primary'];
    $sizes = [
        'default' => 'min-h-10 px-4 py-2',
        'compact' => 'min-h-0 px-0 py-0',
    ];
    $sizeClasses = $sizes[$size] ?? $sizes['default'];
    $buttonClasses = 'inline-flex items-center justify-center gap-2 rounded-lg text-sm font-semibold transition duration-200 ease-ui focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:pointer-events-none disabled:opacity-50 ' . $sizeClasses;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class([$buttonClasses, $variantClasses]) }} @if ($disabled) aria-disabled="true" tabindex="-1" @endif>
        @if ($icon)<i data-lucide="{{ $icon }}" class="size-4" aria-hidden="true"></i>@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->class([$buttonClasses, $variantClasses]) }}>
        @if ($icon)<i data-lucide="{{ $icon }}" class="size-4" aria-hidden="true"></i>@endif
        {{ $slot }}
    </button>
@endif