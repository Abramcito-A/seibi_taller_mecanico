<x-ui.card>
    <div
        id="diagnosis-check-status"
        class="mb-4 rounded-lg border border-seibi-border bg-seibi-workspace px-4 py-3 text-sm text-slate-600"
        role="status"
        aria-live="polite"
    >
        Comprobando si este formulario ya fue procesado...
    </div>
    <div id="diagnosis-completed-alert" class="mb-5 hidden" role="status" aria-live="polite">
        <x-ui.alert variant="info" title="Este hallazgo ya fue registrado">
            La información de este formulario ya se encuentra guardada en el expediente de la orden. No necesitas volver a capturarla.
        </x-ui.alert>
        <div class="mt-3 flex flex-col-reverse gap-2 sm:flex-row sm:flex-wrap">
            <x-ui.button id="diagnosis-registered-link" :href="route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos'])">Ver diagnóstico registrado</x-ui.button>
            @if ($method === 'POST')
                <x-ui.button id="diagnosis-new-link" :href="$newFormUrl ?? route('diagnosticos.create', $orden)" variant="neutral">Agregar otro hallazgo</x-ui.button>
            @endif
        </div>
    </div>
    <form
        id="diagnosis-create-form"
        method="POST"
        action="{{ $formAction }}"
        enctype="multipart/form-data"
        class="space-y-5"
        @if ($method === 'POST')
            data-status-url="{{ $statusUrl }}"
        @endif
        data-envio-uuid="{{ old('envio_uuid', $envioUuid ?? '') }}"
    >
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @else
            <input type="hidden" name="envio_uuid" value="{{ old('envio_uuid', $envioUuid ?? '') }}">
        @endif

        <div>
            <label for="titulo" class="mb-2 block text-sm font-semibold text-seibi-ink">Título</label>
            <input id="titulo" name="titulo" value="{{ old('titulo', $diagnostico->titulo ?? '') }}" required maxlength="150" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">
            @error('titulo')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="severidad" class="mb-2 block text-sm font-semibold text-seibi-ink">Severidad</label>
            <select id="severidad" name="severidad" required class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">
                @foreach (['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('severidad', $diagnostico->severidad ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('severidad')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="descripcion" class="mb-2 block text-sm font-semibold text-seibi-ink">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="5" required maxlength="5000" class="w-full resize-y rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm leading-6 text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">{{ old('descripcion', $diagnostico->descripcion ?? '') }}</textarea>
            @error('descripcion')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="recomendacion" class="mb-2 block text-sm font-semibold text-seibi-ink">Recomendación</label>
            <textarea id="recomendacion" name="recomendacion" rows="4" maxlength="5000" class="w-full resize-y rounded-lg border border-seibi-border bg-white px-3 py-2.5 text-sm leading-6 text-seibi-ink focus:border-seibi-navy focus:outline-none focus:ring-2 focus:ring-seibi-navy/15">{{ old('recomendacion', $diagnostico->recomendacion ?? '') }}</textarea>
            @error('recomendacion')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="fotografias" class="mb-2 block text-sm font-semibold text-seibi-ink">Fotografías técnicas</label>
            <input id="fotografias" name="fotografias[]" type="file" accept="image/jpeg,image/png,image/webp" multiple class="block w-full rounded-lg border border-seibi-border bg-white text-sm text-slate-600 file:mr-4 file:border-0 file:bg-seibi-navy file:px-4 file:py-2.5 file:font-semibold file:text-white hover:file:bg-seibi-navy-dark">
            <p class="mt-2 text-xs text-slate-500">Máximo 5 imágenes. JPEG, PNG o WebP, 5 MB por archivo.</p>
            @error('fotografias')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            @error('fotografias.*')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex flex-wrap justify-end gap-2 pt-2">
            <x-ui.button
                :href="route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos'])"
                variant="neutral"
            >Volver al expediente</x-ui.button>
            <x-ui.button type="submit" data-submit-diagnosis>{{ $submitLabel }}</x-ui.button>
        </div>
    </form>
</x-ui.card>
