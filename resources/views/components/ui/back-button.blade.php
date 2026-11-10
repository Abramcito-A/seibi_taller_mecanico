@props([
    'fallback',
])

<button
    type="button"
    data-back-button
    data-fallback="{{ $fallback }}"
    {{ $attributes->class([
        'group inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm font-semibold text-seibi-navy shadow-sm transition duration-200 ease-ui hover:-translate-y-px hover:border-seibi-navy/30 hover:bg-seibi-workspace active:translate-y-0 active:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-seibi-navy motion-reduce:transform-none motion-reduce:transition-none sm:px-4',
    ]) }}
>
    <i data-lucide="arrow-left" class="size-4 shrink-0 transition-transform duration-200 group-hover:-translate-x-0.5" aria-hidden="true"></i>
    <span>Volver</span>
</button>
