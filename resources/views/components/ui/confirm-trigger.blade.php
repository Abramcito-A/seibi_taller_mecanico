@props([
    'formId',
    'title',
    'message',
    'confirmLabel' => 'Eliminar',
    'variant' => 'destructive',
    'size' => 'default',
])

<x-ui.button :variant="$variant" :size="$size" type="button" data-form-id="{{ $formId }}" data-title="{{ $title }}" data-message="{{ $message }}" data-confirm-label="{{ $confirmLabel }}" @click="$dispatch('confirm-dialog', { formId: $el.dataset.formId, title: $el.dataset.title, message: $el.dataset.message, confirmLabel: $el.dataset.confirmLabel })" {{ $attributes }}>
    {{ $slot }}
</x-ui.button>