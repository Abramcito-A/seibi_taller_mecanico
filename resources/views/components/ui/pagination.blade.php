@props([
    'paginator',
])

@if ($paginator->hasPages())
    <div {{ $attributes->class(['ui-table-pagination']) }}>
        {{ $paginator->onEachSide(1)->links() }}
    </div>
@endif