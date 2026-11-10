@php $prefix = 'detalles['.$index.']'; @endphp
<div data-detail-row class="rounded-lg border border-seibi-border bg-seibi-workspace/40 p-3">
    <div class="grid gap-3 md:grid-cols-12">
        <div class="md:col-span-3">
            <label class="mb-1.5 block text-xs font-semibold text-slate-600">Tipo</label>
            <select name="{{ $prefix }}[tipo_concepto]" required data-detail-type class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">
                @foreach ($detailTypes as $detailType)
                    <option value="{{ $detailType }}" @selected(old($prefix.'.tipo_concepto', $detail['tipo_concepto'] ?? '') === $detailType)>{{ str_replace('_', ' ', ucfirst($detailType)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="md:col-span-5">
            <label class="mb-1.5 block text-xs font-semibold text-slate-600">Descripción</label>
            <input name="{{ $prefix }}[descripcion]" value="{{ old($prefix.'.descripcion', $detail['descripcion'] ?? '') }}" required maxlength="255" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">
            @error($prefix.'.descripcion')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="mb-1.5 block text-xs font-semibold text-slate-600" data-quantity-label>Cantidad</label>
            <input name="{{ $prefix }}[cantidad]" type="number" min="1" max="9999.99" step="1" value="{{ old($prefix.'.cantidad', $detail['cantidad'] ?? '1.00') }}" required data-quantity-input class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">
            @error($prefix.'.cantidad')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="mb-1.5 block text-xs font-semibold text-slate-600">Precio unitario</label>
            <input name="{{ $prefix }}[precio_unitario]" type="number" min="0" max="99999999.99" step="0.01" value="{{ old($prefix.'.precio_unitario', $detail['precio_unitario'] ?? '0.00') }}" required class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">
            @error($prefix.'.precio_unitario')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>
    <div class="mt-3 flex flex-wrap items-end justify-between gap-3 border-t border-seibi-border pt-3">
        <details class="text-sm text-slate-600">
            <summary class="cursor-pointer font-semibold text-seibi-navy">Datos comerciales opcionales</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <input type="hidden" name="{{ $prefix }}[moneda]" value="MXN">
                <label class="space-y-1"><span class="text-xs font-semibold">Tienda de origen</span><input name="{{ $prefix }}[tienda_origen]" value="{{ old($prefix.'.tienda_origen', $detail['tienda_origen'] ?? '') }}" maxlength="150" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2"></label>
                <label class="space-y-1"><span class="text-xs font-semibold">Marca</span><input name="{{ $prefix }}[marca]" value="{{ old($prefix.'.marca', $detail['marca'] ?? '') }}" maxlength="100" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2"></label>
                <label class="space-y-1"><span class="text-xs font-semibold">Número de parte</span><input name="{{ $prefix }}[numero_parte]" value="{{ old($prefix.'.numero_parte', $detail['numero_parte'] ?? '') }}" maxlength="100" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2"></label>
                <label class="space-y-1 sm:col-span-2"><span class="text-xs font-semibold">URL de publicación</span><input type="url" name="{{ $prefix }}[url_origen]" value="{{ old($prefix.'.url_origen', $detail['url_origen'] ?? '') }}" maxlength="2000" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2"></label>
                <label class="space-y-1"><span class="text-xs font-semibold">URL de imagen</span><input type="url" name="{{ $prefix }}[imagen_url]" value="{{ old($prefix.'.imagen_url', $detail['imagen_url'] ?? '') }}" maxlength="2000" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2"></label>
            </div>
        </details>
        <div class="flex items-center gap-4"><span data-line-total class="font-bold text-seibi-ink">$ 0.00 MXN</span><button type="button" data-remove-detail class="text-sm font-semibold text-status-error hover:underline">Eliminar</button></div>
    </div>
</div>
