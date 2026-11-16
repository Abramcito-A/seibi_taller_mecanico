@extends('layouts.app')
@section('title', $cotizacion->folio)
@section('content')
    <nav aria-label="Ruta de navegación" class="mb-4 flex items-center gap-2 text-xs text-slate-500"><a href="{{ route('ordenes.index') }}" class="hover:text-seibi-navy">Órdenes de servicio</a><span>/</span><a href="{{ route('ordenes.show',['orden'=>$orden,'seccion'=>'cotizaciones']) }}" class="hover:text-seibi-navy">{{ $orden->folio }}</a><span>/</span><span class="font-medium text-seibi-ink">{{ $cotizacion->folio }}</span></nav>
    <x-ui.page-header :title="$cotizacion->folio" :description="$cotizacion->titulo">
        <x-slot:actions>
            <x-ui.badge :tone="$cotizacion->estado === 'borrador' ? 'pending' : ($cotizacion->estado === 'aprobada' ? 'success' : ($cotizacion->estado === 'rechazada' || $cotizacion->estado === 'cancelada' ? 'error' : 'info'))">{{ ucfirst($cotizacion->estado) }}</x-ui.badge>
            @if(auth()->user()->hasRole('mecanico') && $cotizacion->estado === 'borrador')<x-ui.button :href="route('cotizaciones.edit',[$orden,$cotizacion])" variant="secondary">Editar borrador</x-ui.button><form method="POST" action="{{ route('cotizaciones.destroy',[$orden,$cotizacion]) }}" onsubmit="return confirm('¿Eliminar este borrador?')">@csrf @method('DELETE')<x-ui.button type="submit" variant="destructive">Eliminar</x-ui.button></form>@endif
            <x-ui.back-button :fallback="route('ordenes.show',['orden'=>$orden,'seccion'=>'cotizaciones'])" />
        </x-slot:actions>
    </x-ui.page-header>
    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <x-ui.card><h2 class="mb-3 text-sm font-semibold text-seibi-ink">Conceptos</h2><div class="overflow-x-auto"><table class="min-w-full text-left"><thead><tr class="border-b border-seibi-border text-xs uppercase text-slate-500"><th class="px-3 py-3">Concepto</th><th class="px-3 py-3 text-right">Cant.</th><th class="px-3 py-3 text-right">Precio</th><th class="px-3 py-3 text-right">Importe</th></tr></thead><tbody class="divide-y divide-seibi-border">@foreach($cotizacion->detalles as $detail)<tr class="text-sm"><td class="px-3 py-3"><div class="flex gap-3">@if($detail->tipo_concepto === 'pieza' && $detail->imageUrl())<img src="{{ $detail->imageUrl() }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-14 w-14 shrink-0 rounded-md border border-seibi-border bg-white object-contain">@endif<div class="min-w-0"><span class="block font-medium text-seibi-ink">{{ $detail->descripcion }}</span><span class="text-xs text-slate-500">{{ str_replace('_',' ',ucfirst($detail->tipo_concepto)) }}@if($detail->marca) · {{ $detail->marca }}@endif @if($detail->fabricante) · Fabricante: {{ $detail->fabricante }}@endif @if($detail->numero_parte) · {{ $detail->numero_parte }}@endif @if($detail->tienda_origen) · {{ $detail->tienda_origen }}@endif</span>
@if($detail->tipo_concepto === 'pieza')
<div class="mt-2 flex flex-wrap items-center gap-3">
    @if($detail->purchaseUrl())
        <a href="{{ $detail->purchaseUrl() }}" target="_blank" rel="noopener noreferrer nofollow" class="inline-flex rounded-md bg-seibi-navy px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">Comprar</a>
    @else
        <span class="text-xs text-slate-400">Enlace de compra no disponible</span>
    @endif
    @if($detail->shoppingUrl())
        <a href="{{ $detail->shoppingUrl() }}" target="_blank" rel="noopener noreferrer nofollow" class="text-xs font-semibold text-seibi-navy hover:underline">Ver en Google Shopping</a>
    @endif
</div>
@if(! empty($detail->detalles_tecnicos['features']) || ! empty($detail->detalles_tecnicos['description']))
<details class="mt-2 text-xs text-slate-600">
    <summary class="cursor-pointer font-semibold text-seibi-navy">Detalles técnicos</summary>
    @if(! empty($detail->detalles_tecnicos['description']))<p class="mt-1">{{ $detail->detalles_tecnicos['description'] }}</p>@endif
    @if(! empty($detail->detalles_tecnicos['features']))
    <ul class="mt-1 list-disc pl-4">
        @foreach($detail->detalles_tecnicos['features'] as $feature)<li>{{ $feature['title'] }}: {{ $feature['value'] }}</li>@endforeach
    </ul>
    @endif
</details>
@endif
@endif</div></div></td><td class="px-3 py-3 text-right">{{ number_format((float)$detail->cantidad,2,'.',',') }}</td><td class="px-3 py-3 text-right">$ {{ number_format((float)$detail->precio_unitario,2,'.',',') }}</td><td class="px-3 py-3 text-right font-semibold">$ {{ number_format((float)$detail->importe,2,'.',',') }}</td></tr>@endforeach</tbody></table></div></x-ui.card>
            @if($cotizacion->descripcion || $cotizacion->observaciones)<x-ui.panel title="Detalles y observaciones"><dl class="space-y-3">@if($cotizacion->descripcion)<div><dt class="text-xs font-semibold uppercase text-slate-500">Descripción</dt><dd class="mt-1 whitespace-pre-line text-sm">{{ $cotizacion->descripcion }}</dd></div>@endif @if($cotizacion->observaciones)<div><dt class="text-xs font-semibold uppercase text-slate-500">Observaciones</dt><dd class="mt-1 whitespace-pre-line text-sm">{{ $cotizacion->observaciones }}</dd></div>@endif</dl></x-ui.panel>@endif
        </div>
        <div class="space-y-5"><x-ui.card><dl class="space-y-4"><div><dt class="text-xs font-semibold uppercase text-slate-500">Orden</dt><dd class="mt-1 text-sm font-medium">{{ $orden->folio }}</dd></div><div><dt class="text-xs font-semibold uppercase text-slate-500">Cliente</dt><dd class="mt-1 text-sm">{{ $orden->vehiculo->cliente->nombre }} {{ $orden->vehiculo->cliente->apellido }}</dd></div><div><dt class="text-xs font-semibold uppercase text-slate-500">Vehículo</dt><dd class="mt-1 text-sm">{{ $orden->vehiculo->marca }} {{ $orden->vehiculo->modelo }} · {{ $orden->vehiculo->anio }}</dd></div><div class="border-t border-seibi-border pt-4"><dt class="text-sm font-semibold text-slate-600">Subtotal</dt><dd class="mt-1 text-right text-sm font-semibold">$ {{ number_format((float)$cotizacion->subtotal,2,'.',',') }} MXN</dd></div><div><dt class="text-base font-bold text-seibi-ink">Total</dt><dd class="mt-1 text-right text-xl font-bold text-seibi-ink">$ {{ number_format((float)$cotizacion->total,2,'.',',') }} MXN</dd></div></dl></x-ui.card></div>
    </div>
@endsection
