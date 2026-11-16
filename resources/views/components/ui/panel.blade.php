@props([
    'title' => null,
    'description' => null,
    'padding' => 'p-5 sm:p-6',
])

<section {{ $attributes->class(['overflow-hidden rounded-panel border border-seibi-border bg-white shadow-panel']) }}>
    @if ($title || $description || isset($actions))
        <header class="flex flex-col gap-3 border-b border-seibi-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
                @if ($title)<h2 class="text-base font-semibold text-seibi-ink">{{ $title }}</h2>@endif
                @if ($description)<p class="mt-1 text-sm text-slate-500">{{ $description }}</p>@endif
            </div>
            @if (isset($actions))<div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>@endif
        </header>
    @endif
    <div class="{{ $padding }}">{{ $slot }}</div>
</section>