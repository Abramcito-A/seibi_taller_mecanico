@props([
    'padding' => 'p-5 sm:p-6',
])

<section {{ $attributes->class(['rounded-panel border border-seibi-border bg-white shadow-panel', $padding]) }}>
    {{ $slot }}
</section>