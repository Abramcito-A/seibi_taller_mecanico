@extends('layouts.app')

@section('title', $orden->folio)

@section('content')
    @php
        $statusTones = [
            'recibida' => 'pending', 'en_diagnostico' => 'info',
            'esperando_aprobacion' => 'pending', 'esperando_refaccion' => 'pending',
            'esperando_refacciones' => 'pending', 'en_reparacion' => 'info',
            'lista_entrega' => 'success', 'entregada' => 'success',
            'cerrada' => 'neutral', 'cancelada' => 'error',
        ];
        $tabs = [
            'resumen' => 'Resumen',
            'recepcion' => 'Recepción',
            'diagnosticos' => 'Diagnósticos',
            'evidencias' => 'Evidencias',
            'historial' => 'Historial',
        ];
        $recepcionEvidencias = $orden->evidencias->where('etapa', 'recepcion');
        $todasEvidencias = $orden->evidencias->whereIn('etapa', ['recepcion', 'diagnostico']);
    @endphp

    <x-ui.page-header :title="$orden->folio" :description="'Ingreso '.$orden->fecha_ingreso->format('d/m/Y · H:i')">
        <x-slot:actions>
            <x-ui.badge :tone="$statusTones[$orden->estado] ?? 'neutral'">{{ $statusLabels[$orden->estado] ?? $orden->estado }}</x-ui.badge>
            <x-ui.back-button :fallback="route('ordenes.index')" />
            @if ($orden->estado === 'recibida' && auth()->user()->hasRole('administrador', 'recepcionista'))
                <x-ui.button :href="route('ordenes.edit', $orden)" variant="secondary">Corregir recepción</x-ui.button>
            @endif
            @if ($orden->estado === 'recibida' && auth()->user()->hasRole('administrador', 'mecanico'))
                <form method="POST" action="{{ route('ordenes.iniciar-diagnostico', $orden) }}">
                    @csrf
                    <x-ui.button type="submit" icon="wrench">Iniciar diagnóstico</x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <nav aria-label="Ruta de navegación" class="mb-4 flex items-center gap-2 text-xs text-slate-500">
        <a href="{{ route('ordenes.index') }}" class="hover:text-seibi-navy">Órdenes de servicio</a>
        <span aria-hidden="true">/</span>
        <span class="font-medium text-seibi-ink">{{ $orden->folio }}</span>
    </nav>

    <x-ui.card class="mb-5">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3 lg:grid-cols-6">
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Cliente</dt><dd class="mt-1 text-sm font-medium text-seibi-ink"><a href="{{ route('clientes.show', $orden->vehiculo->cliente) }}" class="hover:underline">{{ $orden->vehiculo->cliente->nombre }} {{ $orden->vehiculo->cliente->apellido }}</a></dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Vehículo</dt><dd class="mt-1 text-sm font-medium text-seibi-ink">{{ $orden->vehiculo->marca }} {{ $orden->vehiculo->modelo }} · {{ $orden->vehiculo->anio }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Placas</dt><dd class="mt-1 text-sm text-seibi-ink">{{ $orden->vehiculo->placas ?: '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Kilometraje</dt><dd class="mt-1 text-sm text-seibi-ink">{{ $orden->kilometraje_ingreso === null ? '—' : number_format($orden->kilometraje_ingreso).' km' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Estado</dt><dd class="mt-1 text-sm text-seibi-ink">{{ $statusLabels[$orden->estado] ?? $orden->estado }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Responsable</dt><dd class="mt-1 text-sm text-seibi-ink">{{ $orden->usuarioResponsable->name }}</dd></div>
        </dl>
    </x-ui.card>

    <div class="mb-5 overflow-x-auto rounded-lg border border-seibi-border bg-white p-1" role="tablist" aria-label="Secciones del expediente">
        <div class="flex min-w-max gap-1">
            @foreach ($tabs as $tabKey => $tabLabel)
                @php $tabUrl = route('ordenes.show', ['orden' => $orden, 'seccion' => $tabKey]); @endphp
                <a href="{{ $tabUrl }}" role="tab" @if($section === $tabKey) aria-selected="true" @else aria-selected="false" @endif class="rounded-md px-3 py-2 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-seibi-navy {{ $section === $tabKey ? 'bg-seibi-navy text-white shadow-sm' : 'text-slate-600 hover:bg-seibi-workspace hover:text-seibi-ink' }}">{{ $tabLabel }}</a>
            @endforeach
        </div>
    </div>

    @if ($section === 'resumen')
        <div class="grid gap-5 lg:grid-cols-2">
            <x-ui.panel title="Recepción" description="Información principal reportada">
                <dl class="space-y-4">
                    <div><dt class="text-xs font-semibold uppercase text-slate-500">Problema reportado</dt><dd class="mt-1 whitespace-pre-line text-sm leading-6 text-seibi-ink">{{ $orden->descripcion_problema }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase text-slate-500">Observaciones</dt><dd class="mt-1 whitespace-pre-line text-sm text-seibi-ink">{{ $orden->observaciones ?: '—' }}</dd></div>
                </dl>
            </x-ui.panel>
            <x-ui.panel title="Actividad reciente" description="Diagnósticos y cambios principales">
                <p class="text-sm text-seibi-ink">{{ $diagnosticos->total() }} hallazgo(s) registrado(s).</p>
                <p class="mt-2 text-sm text-slate-600">{{ $orden->historialEstados->count() }} cambio(s) de estado registrados.</p>
            </x-ui.panel>
        </div>
    @endif

    @if ($section === 'recepcion')
        <x-ui.panel title="Recepción" description="Datos registrados al ingresar el vehículo">
            <dl class="grid gap-5 sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Fecha de entrada</dt><dd class="mt-1 text-sm text-seibi-ink">{{ $orden->fecha_ingreso->format('d/m/Y · H:i') }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Kilometraje</dt><dd class="mt-1 text-sm text-seibi-ink">{{ $orden->kilometraje_ingreso === null ? '—' : number_format($orden->kilometraje_ingreso).' km' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-500">Problema reportado</dt><dd class="mt-1 whitespace-pre-line text-sm leading-6 text-seibi-ink">{{ $orden->descripcion_problema }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-500">Observaciones</dt><dd class="mt-1 whitespace-pre-line text-sm text-seibi-ink">{{ $orden->observaciones ?: '—' }}</dd></div>
            </dl>
        </x-ui.panel>
    @endif

    @if ($section === 'diagnosticos')
        <x-ui.panel id="diagnosticos" title="Diagnóstico técnico" :description="$diagnosticos->total().' hallazgo(s) encontrado(s)'" padding="p-4 sm:p-5">
            <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <form method="GET" action="{{ route('ordenes.show', ['orden' => $orden, 'seccion' => 'diagnosticos']) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div>
                        <label for="diagnostico_q" class="mb-1.5 block text-xs font-semibold text-slate-600">Buscar por título</label>
                        <input id="diagnostico_q" name="diagnostico_q" value="{{ $diagnosisFilters['q'] }}" class="min-w-52 rounded-lg border border-seibi-border px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="severidad" class="mb-1.5 block text-xs font-semibold text-slate-600">Severidad</label>
                        <select id="severidad" name="severidad" class="rounded-lg border border-seibi-border px-3 py-2 text-sm">
                            <option value="">Todas</option>
                            @foreach(['baja'=>'Baja','media'=>'Media','alta'=>'Alta','critica'=>'Crítica'] as $value=>$label)
                                <option value="{{ $value }}" @selected($diagnosisFilters['severidad'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="hidden" name="seccion" value="diagnosticos">
                    <x-ui.button type="submit" variant="neutral">Filtrar</x-ui.button>
                </form>
                @if ($orden->estado === 'en_diagnostico' && auth()->user()->hasRole('administrador', 'mecanico'))
                    <x-ui.button :href="route('diagnosticos.create', $orden)" icon="plus">Agregar hallazgo</x-ui.button>
                @endif
            </div>

            @if (session('new_diagnosis_created'))
                <div class="mb-4 flex justify-end">
                    <x-ui.button :href="route('diagnosticos.create', $orden)" icon="plus" variant="secondary">Agregar otro hallazgo</x-ui.button>
                </div>
            @endif

            @if ($diagnosticos->isEmpty())
                <x-ui.empty-state title="{{ $diagnosisFilters['q'] !== '' || $diagnosisFilters['severidad'] !== '' ? 'Sin resultados para los filtros' : 'Sin hallazgos técnicos' }}" description="{{ $diagnosisFilters['q'] !== '' || $diagnosisFilters['severidad'] !== '' ? 'Prueba con otro título o severidad.' : 'Todavía no se han registrado diagnósticos para esta orden.' }}" icon="clipboard-list" class="min-h-40 px-0 py-5" />
            @else
                <div class="space-y-3">
                    @foreach($diagnosticos as $diagnostico)
                        @php
                            $tones = ['baja'=>'success','media'=>'pending','alta'=>'error','critica'=>'error'];
                            $photos = $diagnostico->evidencias->where('etapa','diagnostico');
                            $summary = \Illuminate\Support\Str::limit($diagnostico->descripcion, 150);
                        @endphp
                        <article id="diagnostico-{{ $diagnostico->id_diagnostico }}" tabindex="-1" class="rounded-lg border p-4 transition {{ $highlightedDiagnosisId === $diagnostico->id_diagnostico ? 'border-seibi-navy ring-2 ring-seibi-navy/20 bg-seibi-navy/5' : 'border-seibi-border bg-seibi-workspace/40' }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-seibi-ink">{{ $diagnostico->titulo }}</h3>
                                    <p class="mt-1 text-xs text-slate-500">{{ $diagnostico->usuario?->name ?? 'Usuario' }} · {{ $diagnostico->fecha_diagnostico?->format('d/m/Y H:i') }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-ui.badge :tone="$tones[$diagnostico->severidad] ?? 'neutral'">{{ ucfirst($diagnostico->severidad) }}</x-ui.badge>
                                    @if (! in_array($orden->estado,['cerrada','cancelada'],true) && auth()->user()->hasRole('administrador','mecanico'))
                                        <x-ui.button :href="route('diagnosticos.edit',[$orden,$diagnostico])" variant="neutral">Editar</x-ui.button>
                                    @endif
                                </div>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-seibi-ink">{{ $summary }}</p>
                            <details class="mt-3 rounded-md border border-seibi-border bg-white px-3 py-2">
                                <summary class="cursor-pointer text-sm font-semibold text-seibi-navy">Ver descripción y recomendación</summary>
                                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <div><dt class="text-xs font-semibold uppercase text-slate-500">Descripción completa</dt><dd class="mt-1 whitespace-pre-line text-sm leading-6 text-seibi-ink">{{ $diagnostico->descripcion }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase text-slate-500">Recomendación</dt><dd class="mt-1 whitespace-pre-line text-sm leading-6 text-seibi-ink">{{ $diagnostico->recomendacion ?: '—' }}</dd></div>
                                </dl>
                            </details>
                            @if($photos->isNotEmpty())
                                <p class="mt-3 text-xs font-semibold text-slate-600">{{ $photos->count() }} fotografía(s) técnica(s)</p>
                                <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                                    @foreach($photos as $photo)
                                        <a href="{{ route('ordenes.evidencias.show',[$orden,$photo]) }}" target="_blank" rel="noopener" class="overflow-hidden rounded-md border border-seibi-border bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-seibi-navy">
                                            <img src="{{ route('ordenes.evidencias.show',[$orden,$photo]) }}" alt="{{ $photo->nombre_archivo }}" loading="lazy" class="aspect-square w-full object-cover">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
                @php
                    $firstResult = $diagnosticos->firstItem();
                    $lastResult = $diagnosticos->lastItem();
                @endphp
                <div class="mt-5 flex flex-col gap-3 border-t border-seibi-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-600" aria-live="polite">
                        @if ($diagnosticos->total() > 0)
                            Mostrando {{ $firstResult }}–{{ $lastResult }} de {{ $diagnosticos->total() }} hallazgos
                            · Página {{ $diagnosticos->currentPage() }} de {{ $diagnosticos->lastPage() }}
                        @else
                            No hay hallazgos para mostrar
                        @endif
                    </p>
                    <nav aria-label="Paginación de diagnósticos" class="overflow-x-auto">
                        {{ $diagnosticos->onEachSide(1)->links() }}
                    </nav>
                </div>
            @endif
        </x-ui.panel>
    @endif

    @if ($section === 'evidencias')
        <x-ui.panel title="Evidencias privadas" :description="$todasEvidencias->count().' archivo(s)'">
            @if($todasEvidencias->isEmpty())
                <x-ui.empty-state title="Sin evidencias" description="No hay fotografías privadas asociadas a esta orden." icon="camera" class="min-h-48 px-0 py-5" />
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach($todasEvidencias as $photo)
                        <a href="{{ route('ordenes.evidencias.show',[$orden,$photo]) }}" target="_blank" rel="noopener" class="overflow-hidden rounded-lg border border-seibi-border bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-seibi-navy">
                            <img src="{{ route('ordenes.evidencias.show',[$orden,$photo]) }}" alt="{{ $photo->nombre_archivo }}" loading="lazy" class="aspect-[4/3] w-full object-cover">
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @endif

    @if ($section === 'historial')
        <x-ui.panel title="Historial de estado" description="Cambios registrados en el expediente">
            @if($orden->historialEstados->isEmpty())
                <p class="text-sm text-slate-500">No hay cambios de estado registrados.</p>
            @else
                <ol class="space-y-4">
                    @foreach($orden->historialEstados as $cambio)
                        <li class="flex gap-3"><span class="mt-1.5 size-2 shrink-0 rounded-full bg-seibi-navy"></span><div><p class="text-sm font-semibold text-seibi-ink">{{ $statusLabels[$cambio->estado_nuevo] ?? $cambio->estado_nuevo }}</p><p class="mt-1 text-xs text-slate-500">{{ $cambio->usuario?->name ?? 'Sistema' }} · {{ $cambio->fecha_cambio->format('d/m/Y H:i') }}@if($cambio->comentario) · {{ $cambio->comentario }}@endif</p></div></li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>
    @endif
@endsection
