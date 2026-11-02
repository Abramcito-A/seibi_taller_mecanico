@props([
    'href',
    'icon',
    'active' => false,
])

<a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->class([
    'group flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition duration-200 ease-ui focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white',
    'bg-white/12 text-white shadow-sm' => $active,
    'text-white/70 hover:bg-white/8 hover:text-white' => ! $active,
]) }}>
    <i data-lucide="{{ $icon }}" class="size-[1.125rem] shrink-0 transition-transform duration-200 group-hover:scale-105" aria-hidden="true"></i>
    <span>{{ $slot }}</span>
</a>