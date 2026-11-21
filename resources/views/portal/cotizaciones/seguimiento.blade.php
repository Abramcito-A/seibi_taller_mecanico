@extends('layouts.public')
@section('title', 'Seguimiento · '.$ordenFolio)
@section('subtitle', 'Seguimiento de tu reparación')
@section('content')
    {{-- Vista de solo lectura. Solo incluye trabajos de la cotización de este enlace y actualizaciones publicadas;
         nunca notas internas, usuarios del taller, precios de compra, proveedores ni identificadores internos. --}}
    @php
        $works = $tracking['works'];
        $progress = $tracking['progress'];
        $updates = $tracking['updates'];
        $statusClasses = [
            'pendiente' => 'border-seibi-border bg-seibi-workspace text-seibi-ink',
            'en_proceso' => 'border-status-info/20 bg-blue-50 text-status-info',
            'en_espera' => 'border-status-pending/20 bg-amber-50 text-status-pending',
            'completado' => 'border-status-success/20 bg-emerald-50 text-status-success',
            'no_realizado' => 'border-status-error/20 bg-red-50 text-status-error',
        ];
        $priorityClasses = [
            'error' => 'border-status-error/20 bg-red-50 text-status-error',
            'pending' => 'border-status-pending/20 bg-amber-50 text-status-pending',
            'info' => 'border-status-info/20 bg-blue-50 text-status-info',
            'neutral' => 'border-seibi-border bg-seibi-workspace text-seibi-ink',
        ];
        $vehicleLabel = trim($vehiculo->marca.' '.$vehiculo->modelo.' '.$vehiculo->anio);
        $photoUrl = fn ($photo) => $photo->publicReference() ? route('portal.cotizaciones.foto', ['token' => $token, 'reference' => $photo->publicReference()]) : null;
        $rejectedCount = $detalles->where('decision_cliente', 'rechazado')->count();
    @endphp

    <div class="space-y-5" x-data="{ tab: 'seguimiento' }">
        @if (session('portal_notice'))
            <x-ui.alert variant="success">{{ session('portal_notice') }}</x-ui.alert>
        @endif

        {{-- Encabezado --}}
        <section class="rounded-panel border border-seibi-border bg-white p-5 shadow-panel">
            <p class="text-sm text-slate-600">Hola {{ $clienteNombre }}:</p>
            <h1 class="mt-1 text-xl font-semibold leading-7 text-seibi-ink">{{ $tracking['headline'] }}</h1>
            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-seibi-border pt-4 text-sm">
                <div><dt class="text-xs text-slate-500">Orden de servicio</dt><dd class="font-semibold break-all">{{ $ordenFolio }}</dd></div>
                <div><dt class="text-xs text-slate-500">Última actualización</dt><dd class="font-medium">{{ \App\Models\Cotizacion::formatForDisplay($tracking['lastUpdate'], 'j M Y, H:i') }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-slate-500">Vehículo</dt><dd class="font-medium">{{ $vehicleLabel }}@if ($vehiculo->version) · {{ $vehiculo->version }}@endif</dd>@if ($vehiculo->placas)<dd class="text-xs text-slate-500">Placas {{ $vehiculo->placas }}</dd>@endif</div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">Horario de Monterrey.</p>
        </section>

        {{-- Vehículo listo: solo después de la acción explícita del taller --}}
        @if ($tracking['isReady'])
            @php
                $doneWorks = $works->where('estado', 'completado');
                $notDoneWorks = $works->where('estado', 'no_realizado');
            @endphp
            <section class="rounded-panel border border-status-success/30 bg-emerald-50 p-5 shadow-panel">
                <div class="flex items-start gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-white text-status-success" aria-hidden="true"><i data-lucide="circle-check" class="size-6"></i></span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-status-success">¡Tu vehículo está listo!</h2>
                        <p class="mt-0.5 text-sm text-seibi-ink">Desde el {{ \App\Models\Cotizacion::formatForDisplay($tracking['readyAt'], 'j M Y, H:i') }}.</p>
                    </div>
                </div>
                @if ($doneWorks->isNotEmpty())
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-600">Trabajos realizados</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-seibi-ink">
                        @foreach ($doneWorks as $work)<li>{{ $work->descripcion }}</li>@endforeach
                    </ul>
                @endif
                @if ($notDoneWorks->isNotEmpty())
                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-status-error">No se realizaron</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-seibi-ink">
                        @foreach ($notDoneWorks as $work)<li>{{ $work->descripcion }}</li>@endforeach
                    </ul>
                @endif
                <p class="mt-4 rounded-lg bg-white px-3 py-2 text-sm text-seibi-ink">Comunícate con el taller para coordinar la entrega de tu vehículo.</p>
            </section>
        @endif

        {{-- Pestañas --}}
        <div class="grid grid-cols-2 gap-1 rounded-lg border border-seibi-border bg-white p-1" role="tablist" aria-label="Secciones">
            <button type="button" role="tab" class="min-h-11 rounded-md text-sm font-semibold transition" :class="tab === 'seguimiento' ? 'bg-seibi-navy text-white' : 'text-slate-600'" :aria-selected="tab === 'seguimiento'" @click="tab = 'seguimiento'">Seguimiento</button>
            <button type="button" role="tab" class="min-h-11 rounded-md text-sm font-semibold transition" :class="tab === 'cotizacion' ? 'bg-seibi-navy text-white' : 'text-slate-600'" :aria-selected="tab === 'cotizacion'" @click="tab = 'cotizacion'">Mi cotización</button>
        </div>

        <div x-show="tab === 'seguimiento'" class="space-y-5">
            {{-- Etapas generales --}}
            <section class="rounded-panel border border-seibi-border bg-white p-5 shadow-panel">
                <h2 class="text-base font-semibold text-seibi-ink">Etapas</h2>
                <ol class="mt-4 space-y-0">
                    @foreach ($tracking['stages'] as $stage)
                        <li class="relative flex gap-3 pb-4 last:pb-0">
                            @unless ($loop->last)<span class="absolute top-6 left-[0.6875rem] h-[calc(100%-1.25rem)] w-0.5 {{ $stage['state'] === 'done' ? 'bg-status-success' : 'bg-seibi-border' }}" aria-hidden="true"></span>@endunless
                            <span class="relative grid size-6 shrink-0 place-items-center rounded-full border-2 {{ match ($stage['state']) { 'done' => 'border-status-success bg-status-success text-white', 'current' => 'border-seibi-orange bg-white text-seibi-orange', default => 'border-seibi-border bg-white text-slate-400' } }}" aria-hidden="true">
                                @if ($stage['state'] === 'done')<i data-lucide="circle-check" class="size-3.5"></i>@else<span class="size-2 rounded-full {{ $stage['state'] === 'current' ? 'bg-seibi-orange' : 'bg-seibi-border' }}"></span>@endif
                            </span>
                            <div class="min-w-0 pt-0.5">
                                <p class="text-sm font-semibold {{ $stage['state'] === 'upcoming' ? 'text-slate-500' : 'text-seibi-ink' }}">{{ $stage['label'] }}@if ($stage['state'] === 'current')<span class="ml-2 text-xs font-medium text-seibi-orange">Etapa actual</span>@endif</p>
                                @if ($stage['date'])<p class="text-xs text-slate-500">{{ \App\Models\Cotizacion::formatForDisplay($stage['date'], 'j M Y, H:i') }}</p>@endif
                                <span class="sr-only">{{ ['done' => 'Completada', 'current' => 'En curso', 'upcoming' => 'Pendiente'][$stage['state']] }}</span>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- Progreso de los trabajos autorizados --}}
            <section class="rounded-panel border border-seibi-border bg-white p-5 shadow-panel">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-seibi-ink">Avance de los trabajos</h2>
                        <p class="mt-0.5 text-sm text-slate-600">{{ $progress['completado'] }} de {{ $progress['total'] }} completado(s)</p>
                    </div>
                    <p class="text-2xl font-bold text-seibi-ink">{{ $progress['percent'] }}%</p>
                </div>
                <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-seibi-workspace" role="progressbar" aria-label="Avance de los trabajos autorizados" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}">
                    <div class="h-full rounded-full bg-status-success" style="width: {{ $progress['percent'] }}%"></div>
                </div>
                @if ($rejectedCount > 0)
                    <p class="mt-3 text-xs leading-5 text-slate-500">Solo los conceptos que autorizaste forman parte del seguimiento. Los {{ $rejectedCount }} concepto(s) que no autorizaste no se incluyen.</p>
                @endif
            </section>

            {{-- Trabajos autorizados --}}
            <section class="space-y-3">
                <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-slate-500">Trabajos autorizados ({{ $works->count() }})</h2>
                @foreach ($works as $work)
                    @php
                        $latest = $tracking['latestByWork'][$work->id_trabajo] ?? null;
                        $workPhotos = $updates->where('id_trabajo', $work->id_trabajo)->flatMap(fn ($update) => $update->evidencias);
                        $waitingReason = $work->estado === 'en_espera' && $latest?->estado_nuevo === 'en_espera' ? $latest->motivo : null;
                    @endphp
                    <article class="rounded-panel border border-seibi-border bg-white p-4 shadow-panel">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $priorityClasses[$work->priorityTone()] ?? $priorityClasses['neutral'] }}">{{ $work->priorityLabel() }}</span>
                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $statusClasses[$work->estado] ?? $statusClasses['pendiente'] }}">{{ $work->statusLabel() }}</span>
                        </div>
                        <p class="mt-2 break-words text-[0.95rem] font-semibold leading-6 text-seibi-ink">{{ $work->descripcion }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $work->tipo_concepto === 'mano_obra' ? 'Horas' : 'Cantidad' }}: {{ $work->quantityLabel() }}</p>
                        @if ($waitingReason)
                            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm leading-6 text-status-pending"><span class="font-semibold">Motivo de la espera:</span> {{ $waitingReason }}</p>
                        @endif
                        @if ($latest)
                            <div class="mt-3 border-t border-seibi-border pt-3">
                                <p class="text-xs text-slate-500">Última actualización · {{ \App\Models\Cotizacion::formatForDisplay($latest->fecha_publicacion, 'j M Y, H:i') }}</p>
                                <p class="mt-1 text-sm font-semibold text-seibi-ink">{{ $latest->titulo }}</p>
                                @if ($latest->descripcion)<p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{{ $latest->descripcion }}</p>@endif
                            </div>
                        @else
                            <p class="mt-3 border-t border-seibi-border pt-3 text-xs text-slate-500">El taller aún no publica avances de este trabajo.</p>
                        @endif
                        @if ($workPhotos->isNotEmpty())
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                @foreach ($workPhotos as $photo)
                                    @if ($url = $photoUrl($photo))
                                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="overflow-hidden rounded-lg border border-seibi-border bg-seibi-workspace">
                                            <img src="{{ $url }}" alt="Fotografía del trabajo {{ $work->descripcion }}" loading="lazy" decoding="async" class="aspect-square w-full object-cover">
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach
            </section>

            {{-- Línea de tiempo pública --}}
            <section class="rounded-panel border border-seibi-border bg-white p-5 shadow-panel">
                <h2 class="text-base font-semibold text-seibi-ink">Últimas actualizaciones</h2>
                @if ($updates->isEmpty())
                    <p class="mt-2 text-sm text-slate-500">Todavía no hay actualizaciones publicadas. Aquí verás los avances que el taller comparta contigo.</p>
                @else
                    <ol class="mt-4 space-y-5">
                        @foreach ($updates as $update)
                            <li class="flex gap-3">
                                <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $update->tipo === 'revision_final' ? 'bg-status-success' : 'bg-seibi-navy' }}" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs text-slate-500">{{ \App\Models\Cotizacion::formatForDisplay($update->fecha_publicacion, 'j M Y, H:i') }}</p>
                                    <p class="mt-0.5 text-sm font-semibold text-seibi-ink">
                                        @if ($update->tipo === 'correccion')<span class="text-xs font-semibold uppercase text-status-pending">Corrección · </span>@endif
                                        {{ $update->titulo }}
                                    </p>
                                    <p class="text-xs text-slate-500">{{ $update->trabajo?->descripcion ?? 'Revisión general del vehículo' }}</p>
                                    @if ($update->changesStatus())
                                        <p class="mt-1 text-xs text-seibi-ink">Estado: {{ \App\Models\TrabajoReparacion::STATUSES[$update->estado_nuevo]['label'] ?? '' }}</p>
                                    @endif
                                    @if ($update->descripcion)<p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{{ $update->descripcion }}</p>@endif
                                    @if ($update->motivo)<p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600"><span class="font-semibold">Motivo:</span> {{ $update->motivo }}</p>@endif
                                    @if ($update->evidencias->isNotEmpty())
                                        <div class="mt-2 grid grid-cols-3 gap-2">
                                            @foreach ($update->evidencias as $photo)
                                                @if ($url = $photoUrl($photo))
                                                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="overflow-hidden rounded-lg border border-seibi-border bg-seibi-workspace">
                                                        <img src="{{ $url }}" alt="Fotografía de {{ $update->titulo }}" loading="lazy" decoding="async" class="aspect-square w-full object-cover">
                                                    </a>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <p class="px-1 text-xs leading-5 text-slate-500">Esta página es de consulta. Si tienes dudas sobre algún trabajo o necesitas autorizar algo adicional, comunícate con el taller.</p>
        </div>

        <div x-show="tab === 'cotizacion'" x-cloak class="space-y-5">
            @include('portal.cotizaciones._resumen-decision')
        </div>
    </div>
@endsection
