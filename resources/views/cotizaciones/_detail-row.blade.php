@php
    $prefix = 'detalles['.$index.']';
    $errorPrefix = 'detalles.'.$index;
    $isLabor = ($detail['tipo_concepto'] ?? 'pieza') === 'mano_obra';
    $hasCommercialErrors = collect(['tienda_origen', 'marca', 'fabricante', 'numero_parte', 'url_origen', 'imagen_url'])
        ->contains(fn (string $field): bool => $errors->has($errorPrefix.'.'.$field));
    $fromProvider = filled($detail['proveedor'] ?? null);
@endphp
<div data-detail-row @if(filled($detail['id_detalle'] ?? null)) data-detail-id="{{ $detail['id_detalle'] }}" @endif class="rounded-lg border border-seibi-border bg-seibi-workspace/40 p-3">
    @if(filled($detail['id_detalle'] ?? null))
        <input type="hidden" name="{{ $prefix }}[id_detalle]" value="{{ $detail['id_detalle'] }}">
    @endif
    <input type="hidden" name="{{ $prefix }}[moneda]" value="MXN">
    @error($errorPrefix.'.id_detalle')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror
    <div class="grid gap-3 md:grid-cols-12">
        <div class="md:col-span-3">
            <label class="mb-1.5 block text-xs font-semibold text-slate-600">Tipo</label>
            <select name="{{ $prefix }}[tipo_concepto]" required data-detail-type class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">
                @foreach ($detailTypes as $detailType)
                    <option value="{{ $detailType }}" @selected(($detail['tipo_concepto'] ?? '') === $detailType)>{{ str_replace('_', ' ', ucfirst($detailType)) }}</option>
                @endforeach
            </select>
            @error($errorPrefix.'.tipo_concepto')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-5">
            <label class="mb-1.5 block text-xs font-semibold text-slate-600">Descripción</label>
            <input name="{{ $prefix }}[descripcion]" value="{{ $detail['descripcion'] ?? '' }}" required maxlength="255" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">
            @error($errorPrefix.'.descripcion')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="mb-1.5 block text-xs font-semibold text-slate-600" data-quantity-label>{{ $isLabor ? 'Horas' : 'Cantidad' }}</label>
            <input name="{{ $prefix }}[cantidad]" value="{{ $detail['cantidad'] ?? '1.00' }}" type="number" min="{{ $isLabor ? '0.01' : '1' }}" max="9999.99" step="{{ $isLabor ? '0.01' : '1' }}" required data-quantity-input class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">
            @error($errorPrefix.'.cantidad')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="mb-1.5 block text-xs font-semibold text-slate-600">Precio unitario</label>
            <input name="{{ $prefix }}[precio_unitario]" value="{{ $detail['precio_unitario'] ?? '0.00' }}" type="number" min="0" max="99999999.99" step="0.01" required class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">
            @error($errorPrefix.'.precio_unitario')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>
    @php
        $currentPriority = $detail['prioridad'] ?? null;
        $priorityStyles = [
            '' => 'has-checked:border-slate-400 has-checked:bg-white has-checked:text-seibi-ink',
            'necesario' => 'has-checked:border-status-error has-checked:bg-red-50 has-checked:text-status-error',
            'recomendado' => 'has-checked:border-status-pending has-checked:bg-amber-50 has-checked:text-status-pending',
            'opcional' => 'has-checked:border-status-info has-checked:bg-blue-50 has-checked:text-status-info',
        ];
    @endphp
    <fieldset class="mt-3">
        <legend class="mb-1.5 text-xs font-semibold text-slate-600">Prioridad para el cliente <span class="font-normal text-slate-500">· obligatoria para enviar</span></legend>
        <div class="flex flex-wrap gap-2">
            @foreach (['' => 'Sin clasificar'] + collect(\App\Models\DetalleCotizacion::PRIORITIES)->map(fn (array $priority): string => $priority['label'])->all() as $priorityValue => $priorityLabel)
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-seibi-border bg-white px-3 py-1 text-xs font-semibold text-slate-500 transition {{ $priorityStyles[$priorityValue] }}">
                    <input type="radio" name="{{ $prefix }}[prioridad]" value="{{ $priorityValue }}" class="sr-only" @checked((string) $currentPriority === (string) $priorityValue)>
                    {{ $priorityLabel }}
                </label>
            @endforeach
        </div>
        @error($errorPrefix.'.prioridad')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </fieldset>
    <div class="mt-3 flex flex-wrap items-end justify-between gap-3 border-t border-seibi-border pt-3">
        <details class="text-sm text-slate-600" @if($hasCommercialErrors) open @endif>
            <summary class="cursor-pointer font-semibold text-seibi-navy">Datos comerciales opcionales</summary>
            @if($fromProvider)
                <p class="mt-2 text-xs text-slate-500">Oferta obtenida del buscador. Los datos del proveedor (ficha de Google Shopping y detalles técnicos) se conservan al guardar.</p>
            @endif
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    'tienda_origen' => ['Tienda de origen', 150, 'text', ''],
                    'marca' => ['Marca', 100, 'text', ''],
                    'fabricante' => ['Fabricante', 150, 'text', ''],
                    'numero_parte' => ['Número de parte', 100, 'text', ''],
                    'url_origen' => ['URL directa del comercio', 2000, 'url', 'sm:col-span-2'],
                    'imagen_url' => ['URL de imagen', 2000, 'url', ''],
                ] as $field => [$label, $maxLength, $inputType, $span])
                    <label class="space-y-1 {{ $span }}">
                        <span class="text-xs font-semibold">{{ $label }}</span>
                        <input name="{{ $prefix }}[{{ $field }}]" value="{{ $detail[$field] ?? '' }}" type="{{ $inputType }}" maxlength="{{ $maxLength }}" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2">
                        @error($errorPrefix.'.'.$field)<span class="block text-xs text-red-600">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div>
        </details>
        <div class="flex items-center gap-4"><span data-line-total class="font-bold text-seibi-ink">$ 0.00 MXN</span><button type="button" data-remove-detail class="text-sm font-semibold text-status-error hover:underline">Eliminar</button></div>
    </div>
</div>
