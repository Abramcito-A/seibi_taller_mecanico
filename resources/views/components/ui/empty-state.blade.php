@props([
    'title',
    'description' => null,
    'icon' => 'info',
])

<div {{ $attributes->class(['flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="grid size-14 place-items-center rounded-2xl border border-seibi-border bg-seibi-workspace text-seibi-navy">
        <i data-lucide="{{ $icon }}" class="size-6" aria-hidden="true"></i>
    </span>
    <h2 class="mt-4 text-base font-semibold text-seibi-ink">{{ $title }}</h2>
    @if ($description)<p class="mt-1 max-w-md text-sm leading-6 text-slate-500">{{ $description }}</p>@endif
    @if (isset($action))<div class="mt-5">{{ $action }}</div>@endif
</div>