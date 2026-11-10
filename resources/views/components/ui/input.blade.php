@props([
    'name',
    'label',
    'id' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
])

@php
    $fieldId = $id ?? $name;
    $hasError = $errors->has($name);
@endphp

<div class="space-y-1.5">
    <label for="{{ $fieldId }}" class="block text-sm font-semibold text-seibi-ink">
        {{ $label }}
        @if ($required)<span class="text-status-error" aria-hidden="true">*</span><span class="sr-only"> (obligatorio)</span>@endif
    </label>
    <input id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" @if ($required) required @endif aria-invalid="{{ $hasError ? 'true' : 'false' }}" @if ($hasError) aria-describedby="{{ $fieldId }}-error" @elseif ($hint) aria-describedby="{{ $fieldId }}-hint" @endif {{ $attributes->class(['ui-input']) }}>
    @if ($hint && ! $hasError)<p id="{{ $fieldId }}-hint" class="text-xs text-slate-500">{{ $hint }}</p>@endif
    @error($name)<p id="{{ $fieldId }}-error" class="text-xs font-medium text-status-error" role="alert">{{ $message }}</p>@enderror
</div>