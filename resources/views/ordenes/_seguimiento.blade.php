{{-- Pestaña «Seguimiento de reparación». Solo el mecánico registra avances y cambia etapas; los demás roles consultan. --}}
@php
    $canManage = auth()->user()->hasRole('mecanico') && ! $orden->isClosed();
    $works = $tracking['works'];
    $progress = $tracking['progress'];
    $finalReview = $tracking['finalReview'];
    $statuses = \App\Models\TrabajoReparacion::STATUSES;
    $acceptsUpdates = $orden->isInRepair() || $orden->estado === 'lista_entrega';
    $transitions = \App\Services\Reparaciones\RepairProgressService::ORDER_TRANSITIONS;
    $canStartRepair = $works->isNotEmpty() && in_array($orden->estado, $transitions['en_reparacion'], true);
    $canMarkAwaiting = $tracking['hasAwaitingQuotation'] && in_array($orden->estado, $transitions['esperando_aprobacion'], true);
    $canMarkReady = $works->isNotEmpty() && in_array($orden->estado, $transitions['lista_entrega'], true);
    $unfinished = $works->reject(fn ($work) => $work->estado === 'completado');
    $startLabel = match ($orden->estado) {
        'esperando_refaccion', 'esperando_refacciones' => 'Reanudar reparación',
        'lista_entrega' => 'Reabrir reparación',
        default => 'Iniciar reparación',
    };
    $workOptions = $works->map(fn ($work) => [
        'id' => (int) $work->id_trabajo,
        'label' => $work->descripcion,
        'estado' => $work->estado,
        'final' => $work->isFinal(),
        'transitions' => collect($work->allowedTransitions())->map(fn ($status) => ['value' => $status, 'label' => ($work->isFinal() ? 'Reabrir · ' : '').$statuses[$status]['label']])->values(),
        'published' => $work->actualizaciones->filter(fn ($update) => $update->isPublished())->map(fn ($update) => ['id' => (int) $update->id_actualizacion, 'label' => $update->titulo.' · '.\App\Models\Cotizacion::formatForDisplay($update->fecha_publicacion)])->values(),
    ])->values();
    $openForm = $canManage ? old('formulario') : null;
    $statusLabelMap = collect($statuses)->map(fn ($status) => $status['label']);
    $isAvanceRetry = old('formulario') === 'avance';
@endphp

<div class="space-y-5" x-data="{ filter: 'todos', modal: @js($openForm) }">
    {{-- Etapa general de la orden --}}
    <x-ui.panel title="Etapa de la reparación" description="Las etapas generales se cambian manualmente; actualizar un trabajo no cambia el estado de la orden.">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Estado de la orden</dt><dd class="mt-1"><x-ui.badge :tone="$statusTones[$orden->estado] ?? 'neutral'">{{ $statusLabels[$orden->estado] ?? $orden->estado }}</x-ui.badge></dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Revisión final</dt><dd class="mt-1 text-seibi-ink">{{ $finalReview ? 'Registrada el '.\App\Models\Cotizacion::formatForDisplay($finalReview->fecha_creacion) : 'Pendiente' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Trabajos autorizados</dt><dd class="mt-1 text-seibi-ink">{{ $progress['total'] }}</dd></div>
            </dl>

            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    @if ($canMarkAwaiting)
                        <form method="POST" action="{{ route('seguimiento.etapa', $orden) }}">
                            @csrf
                            <input type="hidden" name="estado_orden" value="esperando_aprobacion">
                            <x-ui.button type="submit" variant="secondary">Marcar esperando aprobación</x-ui.button>
                        </form>
                    @endif
                    @if ($canStartRepair)
                        @if ($orden->estado === 'lista_entrega')
                            <x-ui.button variant="secondary" icon="wrench" @click="modal = 'reabrir'">{{ $startLabel }}</x-ui.button>
                        @else
                            <form method="POST" action="{{ route('seguimiento.etapa', $orden) }}">
                                @csrf
                                <input type="hidden" name="estado_orden" value="en_reparacion">
                                <x-ui.button type="submit" icon="wrench">{{ $startLabel }}</x-ui.button>
                            </form>
                        @endif
                    @endif
                    @if ($orden->estado === 'en_reparacion')
                        <x-ui.button variant="neutral" @click="modal = 'refacciones'">Esperando refacciones</x-ui.button>
                    @endif
                    @if ($orden->isInRepair())
                        <x-ui.button variant="secondary" icon="clipboard-list" @click="modal = 'revision'">Registrar revisión final</x-ui.button>
                    @endif
                    @if ($canMarkReady)
                        <x-ui.button icon="circle-check" @click="modal = 'listo'" :disabled="$finalReview === null">Marcar listo para entrega</x-ui.button>
                    @endif
                </div>
            @endif
        </div>

        @if ($works->isEmpty())
            <x-ui.alert variant="info" class="mt-4">Aún no hay trabajos autorizados. Los trabajos se generan cuando el cliente autoriza total o parcialmente una cotización; los conceptos rechazados no generan trabajos.</x-ui.alert>
        @elseif (! $acceptsUpdates && ! $orden->isClosed())
            <x-ui.alert variant="info" class="mt-4">Inicia formalmente la reparación para registrar avances de los trabajos autorizados.</x-ui.alert>
        @endif
        @if ($canMarkReady && $finalReview === null)
            <p class="mt-3 text-xs text-slate-500">Para marcar el vehículo como listo primero registra la revisión final.</p>
        @endif
    </x-ui.panel>

    {{-- Resumen de progreso --}}
    @if ($works->isNotEmpty())
        <x-ui.panel title="Resumen de progreso" description="Calculado sobre los trabajos autorizados por el cliente; los conceptos rechazados no forman parte del total.">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm font-semibold text-seibi-ink">{{ $progress['completado'] }} de {{ $progress['total'] }} trabajo(s) completado(s)</p>
                <p class="text-2xl font-bold text-seibi-ink">{{ $progress['percent'] }}%</p>
            </div>
            <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-seibi-workspace" role="progressbar" aria-label="Avance de la reparación" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}">
                <div class="h-full rounded-full bg-status-success transition-all" style="width: {{ $progress['percent'] }}%"></div>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                @foreach ($statuses as $statusKey => $status)
                    <div class="rounded-lg border border-seibi-border px-3 py-2">
                        <dt class="text-xs text-slate-500">{{ $status['label'] }}</dt>
                        <dd class="text-lg font-semibold text-seibi-ink">{{ $progress[$statusKey] }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.panel>

        {{-- Trabajos autorizados --}}
        <x-ui.panel title="Trabajos autorizados" :description="$works->count().' trabajo(s) en seguimiento'">
            <x-slot:actions>
                @if ($canManage && $acceptsUpdates)
                    <x-ui.button icon="plus" @click="modal = 'avance'">Registrar avance</x-ui.button>
                @endif
            </x-slot:actions>

            <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Filtrar trabajos por estado">
                <button type="button" class="rounded-full border px-3 py-1 text-xs font-semibold transition" :class="filter === 'todos' ? 'border-seibi-navy bg-seibi-navy text-white' : 'border-seibi-border text-slate-600 hover:bg-seibi-workspace'" @click="filter = 'todos'">Todos ({{ $progress['total'] }})</button>
                @foreach ($statuses as $statusKey => $status)
                    <button type="button" class="rounded-full border px-3 py-1 text-xs font-semibold transition" :class="filter === '{{ $statusKey }}' ? 'border-seibi-navy bg-seibi-navy text-white' : 'border-seibi-border text-slate-600 hover:bg-seibi-workspace'" @click="filter = '{{ $statusKey }}'">{{ $status['label'] }} ({{ $progress[$statusKey] }})</button>
                @endforeach
            </div>

            <div class="space-y-3">
                @foreach ($works as $work)
                    @php $publishedPhotos = $work->actualizaciones->filter(fn ($update) => $update->isPublished())->sum(fn ($update) => $update->evidencias->count()); @endphp
                    <details x-show="filter === 'todos' || filter === '{{ $work->estado }}'" class="group rounded-lg border border-seibi-border bg-seibi-workspace/40">
                        <summary class="flex cursor-pointer list-none flex-wrap items-start justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.badge :tone="$work->priorityTone()">{{ $work->priorityLabel() }}</x-ui.badge>
                                    <span class="text-xs text-slate-500">{{ $work->cotizacion->folio }} · v{{ $work->version_cotizacion }}</span>
                                </div>
                                <p class="mt-1.5 font-semibold text-seibi-ink">{{ $work->descripcion }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    Cantidad: {{ $work->quantityLabel() }}
                                    · Autorizado {{ \App\Models\Cotizacion::formatForDisplay($work->fecha_autorizacion, 'd/m/Y') }}
                                    · Última actualización {{ \App\Models\Cotizacion::formatForDisplay($work->fecha_actualizacion ?? $work->fecha_creacion) }}
                                    @if ($publishedPhotos > 0) · {{ $publishedPhotos }} foto(s) publicada(s) @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.badge :tone="$work->statusTone()">{{ $work->statusLabel() }}</x-ui.badge>
                                <i data-lucide="chevron-down" class="size-4 text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i>
                            </div>
                        </summary>
                        <div class="border-t border-seibi-border bg-white p-4">
                            <dl class="mb-4 grid gap-3 text-xs sm:grid-cols-4">
                                <div><dt class="font-semibold uppercase text-slate-500">Tipo</dt><dd class="mt-1 text-seibi-ink">{{ ['pieza' => 'Refacción', 'servicio' => 'Servicio', 'mano_obra' => 'Mano de obra', 'otro' => 'Otro'][$work->tipo_concepto] ?? '—' }}</dd></div>
                                <div><dt class="font-semibold uppercase text-slate-500">Inicio</dt><dd class="mt-1 text-seibi-ink">{{ \App\Models\Cotizacion::formatForDisplay($work->fecha_inicio) }}</dd></div>
                                <div><dt class="font-semibold uppercase text-slate-500">Finalización</dt><dd class="mt-1 text-seibi-ink">{{ \App\Models\Cotizacion::formatForDisplay($work->fecha_finalizacion) }}</dd></div>
                                <div><dt class="font-semibold uppercase text-slate-500">Último responsable</dt><dd class="mt-1 text-seibi-ink">{{ $work->responsable?->name ?? '—' }}</dd></div>
                            </dl>
                            <h4 class="text-sm font-semibold text-seibi-ink">Historial de actualizaciones</h4>
                            @if ($work->actualizaciones->isEmpty())
                                <p class="mt-2 text-sm text-slate-500">Sin actualizaciones registradas.</p>
                            @else
                                <ol class="mt-3 space-y-3">
                                    @foreach ($work->actualizaciones as $update)
                                        @include('ordenes._seguimiento-actualizacion', ['update' => $update, 'canManage' => $canManage])
                                    @endforeach
                                </ol>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        </x-ui.panel>
    @endif

    {{-- Registros de nivel de orden: revisión final --}}
    @if ($tracking['orderUpdates']->isNotEmpty())
        <x-ui.panel title="Revisión final" description="Registros generales de la orden">
            <ol class="space-y-3">
                @foreach ($tracking['orderUpdates'] as $update)
                    @include('ordenes._seguimiento-actualizacion', ['update' => $update, 'canManage' => $canManage])
                @endforeach
            </ol>
        </x-ui.panel>
    @endif

    {{-- Trabajos de versiones sustituidas: solo historial --}}
    @if ($tracking['supersededWorks']->isNotEmpty())
        <details class="rounded-panel border border-seibi-border bg-white p-4 shadow-panel">
            <summary class="cursor-pointer text-sm font-semibold text-seibi-ink">Trabajos de versiones sustituidas ({{ $tracking['supersededWorks']->count() }})</summary>
            <p class="mt-2 text-xs text-slate-500">El cliente autorizó estos conceptos en una versión que después fue sustituida. Se conservan como historial y no forman parte del avance.</p>
            <ul class="mt-3 divide-y divide-seibi-border text-sm">
                @foreach ($tracking['supersededWorks'] as $work)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2"><span>{{ $work->descripcion }} <span class="text-xs text-slate-500">· {{ $work->cotizacion->folio }}</span></span><x-ui.badge :tone="$work->statusTone()">{{ $work->statusLabel() }}</x-ui.badge></li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- Enlaces privados del cliente en modo de seguimiento --}}
    @if ($works->isNotEmpty() || $tracking['trackingAccesses']->isNotEmpty())
        <x-ui.panel title="Enlace del cliente" description="El cliente consulta el seguimiento desde el mismo enlace con el que autorizó la cotización.">
            @if ($tracking['trackingWindowClosed'])
                <x-ui.alert variant="info" class="mb-3">La orden concluyó hace más de {{ (int) config('seibi.tracking_link_days_after_close') }} días; los enlaces ya no permiten consultar el seguimiento.</x-ui.alert>
            @endif
            @if ($tracking['trackingAccesses']->isEmpty())
                <p class="text-sm text-slate-500">No hay enlaces vigentes para las cotizaciones autorizadas de esta orden.</p>
            @else
                <ul class="divide-y divide-seibi-border">
                    @foreach ($tracking['trackingAccesses'] as $access)
                        @php $revokeFormId = 'revoke-access-'.$access->id_acceso; @endphp
                        <li class="flex flex-col gap-3 py-3 sm:flex-row sm:items-end sm:justify-between">
                            <div class="text-sm">
                                <p class="font-semibold text-seibi-ink break-all">{{ $access->correo_destino ?? 'Correo no registrado' }}</p>
                                <p class="text-xs text-slate-500">{{ $access->cotizacion?->folio }} · Última consulta: {{ $access->ultimo_acceso ? \App\Models\Cotizacion::formatForDisplay($access->ultimo_acceso) : 'sin consultas' }}</p>
                            </div>
                            @if ($canManage)
                                <form id="{{ $revokeFormId }}" method="POST" action="{{ route('seguimiento.accesos.revocar', [$orden, $access]) }}" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    @csrf
                                    <label for="motivo-{{ $access->id_acceso }}" class="sr-only">Motivo de la revocación</label>
                                    <input id="motivo-{{ $access->id_acceso }}" name="motivo" required minlength="5" maxlength="500" placeholder="Motivo de la revocación" class="ui-input min-w-56">
                                    <x-ui.confirm-trigger :form-id="$revokeFormId" title="Revocar enlace del cliente" message="El cliente ya no podrá consultar la cotización ni el seguimiento con este enlace. Esta acción no se puede deshacer." confirm-label="Revocar">Revocar</x-ui.confirm-trigger>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    @endif

    @if ($canManage)
        {{-- Modal: registrar avance --}}
        @if ($acceptsUpdates && $works->isNotEmpty())
            <div
                x-cloak x-show="modal === 'avance'" x-transition.opacity
                class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-seibi-navy/55 sm:items-center sm:p-4"
                @keydown.escape.window="modal = null" @click.self="modal = null"
                x-data="{
                    works: @js($workOptions),
                    statusLabels: @js($statusLabelMap),
                    workId: @js((string) old('id_trabajo', '')),
                    status: @js((string) old('estado', '')),
                    type: @js((string) old('tipo', 'avance')),
                    visibility: @js((string) old('visibilidad', 'interna')),
                    get work() { return this.works.find(w => String(w.id) === String(this.workId)) ?? null; },
                    get needsReason() { return ['en_espera', 'no_realizado'].includes(this.status) || (this.status !== '' && this.work?.final); },
                    get reasonLabel() { return this.status === 'en_espera' ? 'Motivo de la espera' : (this.status === 'no_realizado' ? 'Explicación de por qué no se realizó' : 'Motivo de la reapertura'); },
                }"
            >
                <section class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-panel" role="dialog" aria-modal="true" aria-labelledby="avance-title" x-trap.noscroll="modal === 'avance'">
                    <div class="flex items-start justify-between gap-3">
                        <h2 id="avance-title" class="text-lg font-semibold text-seibi-ink">Registrar avance</h2>
                        <button type="button" class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-seibi-workspace" aria-label="Cerrar" @click="modal = null"><i data-lucide="x" class="size-4" aria-hidden="true"></i></button>
                    </div>
                    <form method="POST" action="{{ route('seguimiento.avances.store', $orden) }}" enctype="multipart/form-data" class="mt-4 space-y-4" @submit="$el.querySelector('button[type=submit]').disabled = true">
                        @csrf
                        <input type="hidden" name="formulario" value="avance">

                        <div class="space-y-1.5">
                            <label for="avance-trabajo" class="block text-sm font-semibold text-seibi-ink">Trabajo relacionado <span class="text-status-error" aria-hidden="true">*</span></label>
                            <select id="avance-trabajo" name="id_trabajo" x-model="workId" required class="ui-input" @change="status = ''">
                                <option value="">Selecciona un trabajo autorizado</option>
                                <template x-for="option in works" :key="option.id">
                                    <option :value="option.id" x-text="option.label" :selected="String(option.id) === String(workId)"></option>
                                </template>
                            </select>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <label for="avance-tipo" class="block text-sm font-semibold text-seibi-ink">Tipo de registro</label>
                                <select id="avance-tipo" name="tipo" x-model="type" class="ui-input">
                                    <option value="avance">Avance</option>
                                    <option value="correccion" :disabled="! work || work.published.length === 0">Corrección de una publicación</option>
                                </select>
                            </div>
                            <div class="space-y-1.5" x-show="type === 'avance'">
                                <label for="avance-estado" class="block text-sm font-semibold text-seibi-ink">Estado</label>
                                <select id="avance-estado" name="estado" x-model="status" class="ui-input" :disabled="type !== 'avance'">
                                    <option value="">Sin cambio de estado</option>
                                    <template x-for="option in (work ? work.transitions : [])" :key="option.value">
                                        <option :value="option.value" x-text="option.label" :selected="option.value === status"></option>
                                    </template>
                                </select>
                                <p class="text-xs text-slate-500" x-show="work" x-text="'Estado actual: ' + statusLabels[work?.estado ?? '']"></p>
                            </div>
                            <div class="space-y-1.5" x-show="type === 'correccion'" x-cloak>
                                <label for="avance-corregida" class="block text-sm font-semibold text-seibi-ink">Publicación que corriges</label>
                                <select id="avance-corregida" name="id_actualizacion_corregida" class="ui-input" :disabled="type !== 'correccion'" :required="type === 'correccion'">
                                    <option value="">Selecciona la publicación</option>
                                    <template x-for="option in (work ? work.published : [])" :key="option.id">
                                        <option :value="option.id" x-text="option.label" :selected="String(option.id) === @js((string) old('id_actualizacion_corregida', ''))"></option>
                                    </template>
                                </select>
                                <p class="text-xs text-slate-500">La publicación original se conserva; el cliente verá la corrección como un registro nuevo.</p>
                            </div>
                        </div>

                        <x-ui.input name="titulo" id="avance-titulo" label="Título del avance" required maxlength="150" placeholder="Ej. Instalación de nuevas refacciones" />

                        <div class="space-y-1.5">
                            <label for="avance-descripcion" class="block text-sm font-semibold text-seibi-ink">Descripción <span x-show="status === 'completado'" class="text-xs font-normal text-slate-500">(actividades realizadas, obligatoria)</span></label>
                            <textarea id="avance-descripcion" name="descripcion" rows="4" maxlength="5000" :required="status === 'completado'" class="ui-input resize-y" placeholder="Explica qué se realizó">{{ $isAvanceRetry ? old('descripcion') : '' }}</textarea>
                        </div>

                        <div class="space-y-1.5" x-show="needsReason" x-cloak>
                            <label for="avance-motivo" class="block text-sm font-semibold text-seibi-ink"><span x-text="reasonLabel"></span> <span class="text-status-error" aria-hidden="true">*</span></label>
                            <textarea id="avance-motivo" name="motivo" rows="3" maxlength="2000" :required="needsReason" :disabled="! needsReason" class="ui-input resize-y">{{ $isAvanceRetry ? old('motivo') : '' }}</textarea>
                        </div>

                        <div class="space-y-1.5">
                            <label for="avance-fotos" class="block text-sm font-semibold text-seibi-ink">Evidencias fotográficas</label>
                            <input id="avance-fotos" type="file" name="fotografias[]" multiple accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-seibi-workspace file:px-3 file:py-2 file:text-sm file:font-semibold file:text-seibi-ink">
                            <p class="text-xs text-slate-500">Hasta {{ \App\Services\Reparaciones\RepairProgressService::MAX_PHOTOS }} imágenes JPEG, PNG o WebP de 5 MB como máximo. Se guardan en almacenamiento privado.</p>
                        </div>

                        <fieldset class="space-y-2">
                            <legend class="text-sm font-semibold text-seibi-ink">Visibilidad</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-seibi-border p-3 text-sm has-checked:border-seibi-navy has-checked:bg-seibi-workspace">
                                    <input type="radio" name="visibilidad" value="interna" x-model="visibility" class="mt-0.5">
                                    <span><span class="font-semibold">Actualización interna</span><span class="block text-xs text-slate-500">Solo la ve el personal del taller.</span></span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-seibi-border p-3 text-sm has-checked:border-status-success has-checked:bg-emerald-50">
                                    <input type="radio" name="visibilidad" value="cliente" x-model="visibility" class="mt-0.5">
                                    <span><span class="font-semibold">Publicar al cliente</span><span class="block text-xs text-slate-500">Visible en el portal del cliente.</span></span>
                                </label>
                            </div>
                            <div x-show="visibility === 'cliente'" x-cloak class="rounded-lg border border-status-pending/30 bg-amber-50 p-3 text-sm text-status-pending">
                                <p class="font-semibold">El cliente verá este contenido</p>
                                <p class="mt-1 text-xs leading-5">El título, la descripción, el motivo y todas las fotografías seleccionadas aparecerán en su portal. Una vez publicado no se puede editar ni retirar; solo corregir con un registro nuevo.</p>
                                <label class="mt-2 flex items-start gap-2 text-seibi-ink">
                                    <input type="checkbox" name="confirmar_publicacion" value="1" :required="visibility === 'cliente'" class="mt-1 size-4">
                                    <span class="text-xs">Revisé el contenido y las fotografías y confirmo que pueden mostrarse al cliente.</span>
                                </label>
                            </div>
                        </fieldset>

                        <div class="flex flex-col-reverse gap-2 border-t border-seibi-border pt-4 sm:flex-row sm:justify-end">
                            <x-ui.button variant="neutral" @click="modal = null">Cancelar</x-ui.button>
                            <x-ui.button type="submit">Guardar avance</x-ui.button>
                        </div>
                    </form>
                </section>
            </div>
        @endif

        {{-- Modal: revisión final --}}
        @if ($orden->isInRepair())
            <div x-cloak x-show="modal === 'revision'" x-transition.opacity class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-seibi-navy/55 sm:items-center sm:p-4" @keydown.escape.window="modal = null" @click.self="modal = null" x-data="{ visibility: @js((string) (old('formulario') === 'revision' ? old('visibilidad', 'interna') : 'interna')) }">
                <section class="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-panel" role="dialog" aria-modal="true" aria-labelledby="revision-title" x-trap.noscroll="modal === 'revision'">
                    <h2 id="revision-title" class="text-lg font-semibold text-seibi-ink">Registrar revisión final</h2>
                    <p class="mt-1 text-sm text-slate-600">Describe las pruebas y verificaciones realizadas. Registrarla no marca el vehículo como listo.</p>
                    <form method="POST" action="{{ route('seguimiento.revision-final.store', $orden) }}" enctype="multipart/form-data" class="mt-4 space-y-4" @submit="$el.querySelector('button[type=submit]').disabled = true">
                        @csrf
                        <input type="hidden" name="formulario" value="revision">
                        <x-ui.input name="titulo" id="revision-titulo" label="Título" maxlength="150" :value="old('formulario') === 'revision' ? old('titulo') : 'Revisión final del vehículo'" />
                        <div class="space-y-1.5">
                            <label for="revision-descripcion" class="block text-sm font-semibold text-seibi-ink">Resultado de la revisión <span class="text-status-error" aria-hidden="true">*</span></label>
                            <textarea id="revision-descripcion" name="descripcion" rows="4" required minlength="5" maxlength="5000" class="ui-input resize-y" placeholder="Ej. Prueba de manejo sin ruidos, frenos y niveles verificados.">{{ old('formulario') === 'revision' ? old('descripcion') : '' }}</textarea>
                        </div>
                        <div class="space-y-1.5">
                            <label for="revision-fotos" class="block text-sm font-semibold text-seibi-ink">Fotografías</label>
                            <input id="revision-fotos" type="file" name="fotografias[]" multiple accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-seibi-workspace file:px-3 file:py-2 file:text-sm file:font-semibold file:text-seibi-ink">
                        </div>
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-semibold text-seibi-ink">Visibilidad</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="flex items-center gap-2 rounded-lg border border-seibi-border p-3 text-sm has-checked:border-seibi-navy has-checked:bg-seibi-workspace"><input type="radio" name="visibilidad" value="interna" x-model="visibility"> Actualización interna</label>
                                <label class="flex items-center gap-2 rounded-lg border border-seibi-border p-3 text-sm has-checked:border-status-success has-checked:bg-emerald-50"><input type="radio" name="visibilidad" value="cliente" x-model="visibility"> Publicar al cliente</label>
                            </div>
                            <label x-show="visibility === 'cliente'" x-cloak class="flex items-start gap-2 rounded-lg border border-status-pending/30 bg-amber-50 p-3 text-xs text-status-pending">
                                <input type="checkbox" name="confirmar_publicacion" value="1" :required="visibility === 'cliente'" class="mt-0.5 size-4">
                                <span>El resultado y las fotografías serán visibles para el cliente y no podrán retirarse. Confirmo que pueden mostrarse.</span>
                            </label>
                        </fieldset>
                        <div class="flex flex-col-reverse gap-2 border-t border-seibi-border pt-4 sm:flex-row sm:justify-end">
                            <x-ui.button variant="neutral" @click="modal = null">Cancelar</x-ui.button>
                            <x-ui.button type="submit">Registrar revisión</x-ui.button>
                        </div>
                    </form>
                </section>
            </div>
        @endif

        {{-- Modal: esperando refacciones --}}
        @if ($orden->estado === 'en_reparacion')
            <div x-cloak x-show="modal === 'refacciones'" x-transition.opacity class="fixed inset-0 z-50 grid place-items-center bg-seibi-navy/55 p-4" @keydown.escape.window="modal = null" @click.self="modal = null">
                <section class="w-full max-w-md rounded-panel bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="refacciones-title" x-trap.noscroll="modal === 'refacciones'">
                    <h2 id="refacciones-title" class="text-lg font-semibold text-seibi-ink">Orden en espera de refacciones</h2>
                    <form method="POST" action="{{ route('seguimiento.etapa', $orden) }}" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="formulario" value="refacciones">
                        <input type="hidden" name="estado_orden" value="esperando_refacciones">
                        <x-ui.textarea name="comentario" id="refacciones-comentario" label="Refacciones que se esperan" rows="3" required />
                        <div class="flex justify-end gap-2"><x-ui.button variant="neutral" @click="modal = null">Cancelar</x-ui.button><x-ui.button type="submit">Guardar</x-ui.button></div>
                    </form>
                </section>
            </div>
        @endif

        {{-- Modal: reabrir una orden lista para entrega --}}
        @if ($orden->estado === 'lista_entrega' && $canStartRepair)
            <div x-cloak x-show="modal === 'reabrir'" x-transition.opacity class="fixed inset-0 z-50 grid place-items-center bg-seibi-navy/55 p-4" @keydown.escape.window="modal = null" @click.self="modal = null">
                <section class="w-full max-w-md rounded-panel bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="reabrir-title" x-trap.noscroll="modal === 'reabrir'">
                    <h2 id="reabrir-title" class="text-lg font-semibold text-seibi-ink">Reabrir la reparación</h2>
                    <p class="mt-1 text-sm text-slate-600">El cliente dejará de ver el vehículo como listo y será necesaria una nueva revisión final.</p>
                    <form method="POST" action="{{ route('seguimiento.etapa', $orden) }}" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="formulario" value="reabrir">
                        <input type="hidden" name="estado_orden" value="en_reparacion">
                        <x-ui.textarea name="comentario" id="reabrir-comentario" label="Motivo" rows="3" required />
                        <div class="flex justify-end gap-2"><x-ui.button variant="neutral" @click="modal = null">Cancelar</x-ui.button><x-ui.button type="submit">Reabrir</x-ui.button></div>
                    </form>
                </section>
            </div>
        @endif

        {{-- Modal: marcar listo para entrega --}}
        @if ($canMarkReady && $finalReview !== null)
            <div x-cloak x-show="modal === 'listo'" x-transition.opacity class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-seibi-navy/55 sm:items-center sm:p-4" @keydown.escape.window="modal = null" @click.self="modal = null">
                <section class="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-panel" role="dialog" aria-modal="true" aria-labelledby="listo-title" x-trap.noscroll="modal === 'listo'">
                    <h2 id="listo-title" class="text-lg font-semibold text-seibi-ink">Marcar vehículo listo para entrega</h2>
                    <p class="mt-1 text-sm text-slate-600">Revisa el resultado de los trabajos autorizados. El cliente verá el vehículo como listo en su portal.</p>
                    <ul class="mt-4 divide-y divide-seibi-border rounded-lg border border-seibi-border text-sm">
                        @foreach ($works as $work)
                            <li class="flex items-center justify-between gap-3 px-3 py-2"><span class="min-w-0 break-words">{{ $work->descripcion }}</span><x-ui.badge :tone="$work->statusTone()">{{ $work->statusLabel() }}</x-ui.badge></li>
                        @endforeach
                    </ul>
                    <form method="POST" action="{{ route('seguimiento.etapa', $orden) }}" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="formulario" value="listo">
                        <input type="hidden" name="estado_orden" value="lista_entrega">
                        @if ($unfinished->isNotEmpty())
                            <x-ui.alert variant="warning">
                                <p class="font-semibold">{{ $unfinished->count() }} trabajo(s) no están completados</p>
                                <p class="mt-1">{{ $unfinished->map(fn ($work) => $work->descripcion.' ('.mb_strtolower($work->statusLabel()).')')->join('; ') }}.</p>
                                <label class="mt-2 flex items-start gap-2 text-seibi-ink">
                                    <input type="checkbox" name="confirmar_advertencias" value="1" required class="mt-0.5 size-4">
                                    <span>Revisé los trabajos pendientes, en espera o no realizados y aun así el vehículo puede entregarse.</span>
                                </label>
                            </x-ui.alert>
                        @endif
                        <x-ui.textarea name="comentario" id="listo-comentario" label="Comentario (opcional)" rows="2" />
                        <div class="flex justify-end gap-2"><x-ui.button variant="neutral" @click="modal = null">Cancelar</x-ui.button><x-ui.button type="submit" icon="circle-check">Marcar listo</x-ui.button></div>
                    </form>
                </section>
            </div>
        @endif
    @endif
</div>
