@props([
    'title',
    'description' => null,
])

<header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        <p class="mb-2 text-xs font-semibold uppercase text-slate-500">SEIBI · Taller</p>
        <h1 class="text-2xl font-bold leading-tight text-seibi-ink sm:text-[1.75rem]">{{ $title }}</h1>
        @if ($description)<p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ $description }}</p>@endif
    </div>
    @if (isset($actions))<div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>@endif
</header>