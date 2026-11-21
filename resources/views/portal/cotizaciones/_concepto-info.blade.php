{{-- Información de un concepto visible para el cliente. No incluye comercio, precio de compra, enlaces ni datos del proveedor. --}}
@php
    $priority = \App\Models\DetalleCotizacion::PRIORITIES[$detail->prioridad] ?? null;
    $priorityClasses = [
        'error' => 'border-status-error/20 bg-red-50 text-status-error',
        'pending' => 'border-status-pending/20 bg-amber-50 text-status-pending',
        'info' => 'border-status-info/20 bg-blue-50 text-status-info',
    ];
    $isLabor = $detail->tipo_concepto === 'mano_obra';
    $typeLabels = ['pieza' => 'Refacción', 'servicio' => 'Servicio', 'mano_obra' => 'Mano de obra', 'otro' => 'Otro'];
    $quantity = $isLabor
        ? number_format((float) $detail->cantidad, 2, '.', ',').' h'
        : number_format((float) $detail->cantidad, 0, '.', ',');
@endphp
<div class="flex gap-3">
    @if ($detail->tipo_concepto === 'pieza' && $detail->imageUrl())
        <img src="{{ $detail->imageUrl() }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" class="size-16 shrink-0 rounded-lg border border-seibi-border bg-white object-contain" onerror="this.remove()">
    @endif
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
            @if ($priority)
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $priorityClasses[$priority['tone']] ?? '' }}">{{ $priority['label'] }}</span>
            @endif
            <span class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $typeLabels[$detail->tipo_concepto] ?? 'Concepto' }}</span>
        </div>
        <p class="mt-1.5 break-words text-[0.95rem] font-semibold leading-6 text-seibi-ink">{{ $detail->descripcion }}</p>
        @if ($detail->marca || $detail->numero_parte)
            <p class="mt-0.5 text-xs text-slate-500">
                @if ($detail->marca)Marca: {{ $detail->marca }}@endif
                @if ($detail->marca && $detail->numero_parte) · @endif
                @if ($detail->numero_parte)No. de parte: {{ $detail->numero_parte }}@endif
            </p>
        @endif
        @if ($priority)
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $priority['description'] }}</p>
        @endif
        <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
            <div><dt class="text-slate-500">{{ $isLabor ? 'Horas' : 'Cantidad' }}</dt><dd class="font-medium text-seibi-ink">{{ $quantity }}</dd></div>
            <div><dt class="text-slate-500">Precio unitario</dt><dd class="font-medium text-seibi-ink">$ {{ number_format((float) $detail->precio_unitario, 2, '.', ',') }}</dd></div>
            <div class="text-right"><dt class="text-slate-500">Importe</dt><dd class="text-sm font-bold text-seibi-ink">$ {{ number_format((float) $detail->importe, 2, '.', ',') }}</dd></div>
        </dl>
    </div>
</div>
