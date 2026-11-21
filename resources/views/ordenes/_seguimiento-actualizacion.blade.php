{{-- Registro de la bitácora de reparación (panel interno). Los registros no se editan; uno interno puede publicarse. --}}
@php
    $statusLabels = collect(\App\Models\TrabajoReparacion::STATUSES)->map(fn ($status) => $status['label']);
    $isPublished = $update->isPublished();
    $publishFormId = 'publish-update-'.$update->id_actualizacion;
@endphp
<li class="relative flex gap-3">
    <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $isPublished ? 'bg-status-success' : 'bg-slate-400' }}" aria-hidden="true"></span>
    <div class="min-w-0 flex-1 rounded-lg border p-3 {{ $isPublished ? 'border-status-success/25 bg-emerald-50/40' : 'border-dashed border-seibi-border bg-white' }}">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-seibi-ink">
                    @if ($update->tipo !== 'avance')<span class="mr-1 text-xs font-semibold uppercase text-slate-500">{{ $update->typeLabel() }} ·</span>@endif
                    {{ $update->titulo }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500">{{ $update->usuario?->name ?? 'Usuario no disponible' }} · {{ \App\Models\Cotizacion::formatForDisplay($update->fecha_creacion) }}</p>
            </div>
            @if ($isPublished)
                <x-ui.badge tone="success"><i data-lucide="eye" class="size-3.5" aria-hidden="true"></i> Publicada al cliente</x-ui.badge>
            @else
                <x-ui.badge tone="neutral"><i data-lucide="eye-off" class="size-3.5" aria-hidden="true"></i> Interna</x-ui.badge>
            @endif
        </div>

        @if ($update->changesStatus())
            <p class="mt-2 text-xs text-seibi-ink">
                Estado: <span class="font-semibold">{{ $statusLabels[$update->estado_anterior] ?? $update->estado_anterior }}</span>
                <i data-lucide="arrow-right" class="inline size-3" aria-hidden="true"></i>
                <span class="font-semibold">{{ $statusLabels[$update->estado_nuevo] ?? $update->estado_nuevo }}</span>
            </p>
        @endif
        @if ($update->corregida)
            <p class="mt-2 text-xs text-status-pending">Corrige la publicación «{{ $update->corregida->titulo }}» del {{ \App\Models\Cotizacion::formatForDisplay($update->corregida->fecha_creacion) }}. La publicación original se conserva.</p>
        @endif
        @if ($update->descripcion)
            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-seibi-ink">{{ $update->descripcion }}</p>
        @endif
        @if ($update->motivo)
            <p class="mt-2 whitespace-pre-line rounded-md bg-amber-50 px-2.5 py-2 text-xs leading-5 text-status-pending"><span class="font-semibold">Motivo:</span> {{ $update->motivo }}</p>
        @endif

        @if ($update->evidencias->isNotEmpty())
            <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                @foreach ($update->evidencias as $photo)
                    <a href="{{ route('ordenes.evidencias.show', [$orden, $photo]) }}" target="_blank" rel="noopener" class="overflow-hidden rounded-md border border-seibi-border bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-seibi-navy">
                        <img src="{{ route('ordenes.evidencias.show', [$orden, $photo]) }}" alt="{{ $photo->nombre_archivo }}" loading="lazy" class="aspect-square w-full object-cover">
                    </a>
                @endforeach
            </div>
        @endif

        @if ($isPublished)
            <p class="mt-2 text-[0.6875rem] text-slate-500">Publicada el {{ \App\Models\Cotizacion::formatForDisplay($update->fecha_publicacion) }}@if ($update->usuarioPublicacion) por {{ $update->usuarioPublicacion->name }}@endif. Para corregirla registra una corrección; no se puede editar ni eliminar.</p>
        @elseif ($canManage)
            <form id="{{ $publishFormId }}" method="POST" action="{{ route('seguimiento.avances.publicar', [$orden, $update]) }}" class="mt-2">@csrf</form>
            <x-ui.confirm-trigger :form-id="$publishFormId" variant="secondary" class="mt-2" title="Publicar al cliente" :message="'El título, la descripción, el motivo y '.$update->evidencias->count().' fotografía(s) de «'.$update->titulo.'» serán visibles para el cliente en su portal. Una vez publicada no se puede retirar ni editar.'" confirm-label="Publicar">
                <i data-lucide="eye" class="size-4" aria-hidden="true"></i> Publicar al cliente
            </x-ui.confirm-trigger>
        @endif
    </div>
</li>
