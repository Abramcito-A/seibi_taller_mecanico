<div x-data="{ open: false, formId: '', title: 'Confirmar acción', message: '', confirmLabel: 'Confirmar' }" @confirm-dialog.window="open = true; formId = $event.detail.formId; title = $event.detail.title; message = $event.detail.message; confirmLabel = $event.detail.confirmLabel || 'Confirmar'; $nextTick(() => $refs.cancel.focus())" @keydown.escape.window="open = false">
    <div x-cloak x-show="open" x-transition.opacity class="fixed inset-0 z-[60] grid place-items-center overflow-y-auto bg-seibi-navy/55 p-4" @click.self="open = false" role="presentation">
        <section x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-2 scale-[0.98] opacity-0" x-transition:enter-end="translate-y-0 scale-100 opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0 scale-100 opacity-100" x-transition:leave-end="translate-y-2 scale-[0.98] opacity-0" x-trap.noscroll="open" class="w-full max-w-md rounded-panel border border-seibi-border bg-white p-6 shadow-2xl" role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message">
            <div class="flex gap-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-red-50 text-status-error"><i data-lucide="triangle-alert" class="size-5" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <h2 id="confirm-dialog-title" class="text-lg font-semibold text-seibi-ink" x-text="title"></h2>
                    <p id="confirm-dialog-message" class="mt-2 text-sm leading-6 text-slate-600" x-text="message"></p>
                </div>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="neutral" x-ref="cancel" @click="open = false">Cancelar</x-ui.button>
                <x-ui.button variant="destructive" @click="document.getElementById(formId)?.requestSubmit(); open = false" x-text="confirmLabel"></x-ui.button>
            </div>
        </section>
    </div>
</div>