@extends('layouts.public')
@section('title', 'Cotización '.$cotizacion->folio)
@section('subtitle', 'Revisa y autoriza tu cotización')
@section('content')
    @php
        $items = $detalles->map(fn ($detail) => [
            'id' => (int) $detail->id_detalle,
            'cents' => \App\Models\DetalleCotizacion::decimalToHundredths((string) $detail->importe),
            'necessary' => $detail->isNecessary(),
            'label' => $detail->descripcion,
        ])->values();
        $oldDecisions = collect(old('decisiones', []))->filter(fn ($value) => in_array($value, ['aceptado', 'rechazado'], true))->all();
        $oldRisks = collect(old('confirmar_riesgo', []))->map(fn ($value) => $value === '1')->all();
        $vehicleLabel = trim($vehiculo->marca.' '.$vehiculo->modelo.' '.$vehiculo->anio);
    @endphp

    <div
        x-data="{
            items: @js($items),
            decisions: @js((object) $oldDecisions),
            risks: @js((object) $oldRisks),
            reviewing: false,
            money(cents) { return '$ ' + (cents / 100).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            get selectedCents() { return this.items.filter(i => this.decisions[i.id] === 'aceptado').reduce((sum, i) => sum + i.cents, 0); },
            get pendingCount() { return this.items.filter(i => ! this.decisions[i.id]).length; },
            get missingRiskCount() { return this.items.filter(i => i.necessary && this.decisions[i.id] === 'rechazado' && ! this.risks[i.id]).length; },
            get accepted() { return this.items.filter(i => this.decisions[i.id] === 'aceptado'); },
            get rejected() { return this.items.filter(i => this.decisions[i.id] === 'rechazado'); },
            get canReview() { return this.pendingCount === 0 && this.missingRiskCount === 0; },
            acceptAll() { this.items.forEach(i => { this.decisions[i.id] = 'aceptado'; }); },
        }"
        class="space-y-5 pb-28"
    >
        @if (session('portal_notice'))
            <x-ui.alert variant="info">{{ session('portal_notice') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="error">
                <p class="font-semibold">No se registró tu decisión.</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-4">
                    @foreach (collect($errors->all())->unique() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <section class="rounded-panel border border-seibi-border bg-white p-5 shadow-panel">
            <p class="text-sm text-slate-600">Hola {{ $clienteNombre }}:</p>
            <h1 class="mt-1 text-xl font-semibold leading-7 text-seibi-ink">{{ $cotizacion->titulo }}</h1>
            @if ($cotizacion->descripcion)
                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ $cotizacion->descripcion }}</p>
            @endif
            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-seibi-border pt-4 text-sm">
                <div><dt class="text-xs text-slate-500">Cotización</dt><dd class="font-semibold break-all">{{ $cotizacion->folio }}</dd><dd class="text-xs text-slate-500">Versión {{ $cotizacion->version }}</dd></div>
                <div><dt class="text-xs text-slate-500">Orden de servicio</dt><dd class="font-semibold break-all">{{ $ordenFolio }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-slate-500">Vehículo</dt><dd class="font-medium">{{ $vehicleLabel }}@if ($vehiculo->version) · {{ $vehiculo->version }}@endif</dd>@if ($vehiculo->placas)<dd class="text-xs text-slate-500">Placas {{ $vehiculo->placas }}</dd>@endif</div>
                <div><dt class="text-xs text-slate-500">Emitida</dt><dd class="font-medium">{{ \App\Models\Cotizacion::formatForDisplay($cotizacion->fecha_envio, 'j M Y, H:i') }}</dd></div>
                <div><dt class="text-xs text-slate-500">Vigente hasta</dt><dd class="font-medium">{{ \App\Models\Cotizacion::formatForDisplay($expiresAt, 'j M Y, H:i') }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">Horarios de Monterrey. Precios en pesos mexicanos (MXN).</p>
        </section>

        <section class="rounded-panel border border-seibi-border bg-white p-5 shadow-panel">
            <h2 class="text-base font-semibold text-seibi-ink">Cómo decidir</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">Para cada concepto elige <strong>Autorizar</strong> o <strong>Rechazar</strong>. El importe final será la suma de lo que autorices. Una vez confirmada, tu decisión no se puede cambiar desde este enlace; si necesitas cambiarla, comunícate con el taller.</p>
            <ul class="mt-3 space-y-1.5 text-xs leading-5 text-slate-600">
                @foreach (\App\Models\DetalleCotizacion::PRIORITIES as $priority)
                    <li><span class="font-semibold text-seibi-ink">{{ $priority['label'] }}:</span> {{ $priority['description'] }}</li>
                @endforeach
            </ul>
            <button type="button" class="mt-4 text-sm font-semibold text-seibi-navy underline underline-offset-2" @click="acceptAll()">Marcar todos como autorizados</button>
        </section>

        <form id="portal-decision-form" method="POST" action="{{ route('portal.cotizaciones.decide', ['token' => $token]) }}" class="space-y-3" @submit="$el.querySelectorAll('button[type=submit]').forEach(button => button.disabled = true)">
            @csrf
            <input type="hidden" name="version" value="{{ $cotizacion->version }}">
            <input type="hidden" name="revision" value="{{ $cotizacion->revision }}">

            <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-slate-500">Conceptos cotizados ({{ $detalles->count() }})</h2>

            @foreach ($detalles as $detail)
                @php $detailId = (int) $detail->id_detalle; @endphp
                <article class="rounded-panel border bg-white p-4 shadow-panel transition" :class="decisions[{{ $detailId }}] === 'aceptado' ? 'border-status-success/40' : (decisions[{{ $detailId }}] === 'rechazado' ? 'border-status-error/40' : 'border-seibi-border')">
                    @include('portal.cotizaciones._concepto-info', ['detail' => $detail])

                    <fieldset class="mt-4">
                        <legend class="sr-only">Decisión sobre {{ $detail->descripcion }}</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-seibi-border px-3 text-sm font-semibold text-slate-600 transition has-checked:border-status-success has-checked:bg-emerald-50 has-checked:text-status-success">
                                <input type="radio" name="decisiones[{{ $detailId }}]" value="aceptado" x-model="decisions[{{ $detailId }}]" class="sr-only" @checked(($oldDecisions[$detailId] ?? null) === 'aceptado')>
                                Autorizar
                            </label>
                            <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-seibi-border px-3 text-sm font-semibold text-slate-600 transition has-checked:border-status-error has-checked:bg-red-50 has-checked:text-status-error">
                                <input type="radio" name="decisiones[{{ $detailId }}]" value="rechazado" x-model="decisions[{{ $detailId }}]" class="sr-only" @checked(($oldDecisions[$detailId] ?? null) === 'rechazado')>
                                Rechazar
                            </label>
                        </div>
                        @error('decisiones.'.$detailId)<p class="mt-1.5 text-xs text-status-error">{{ $message }}</p>@enderror
                    </fieldset>

                    @if ($detail->isNecessary())
                        <div x-cloak x-show="decisions[{{ $detailId }}] === 'rechazado'" class="mt-3 rounded-lg border border-status-error/30 bg-red-50 p-3 text-sm text-status-error" role="alert">
                            <p class="font-semibold">Atención: este concepto es necesario</p>
                            <p class="mt-1 leading-6">El mecánico lo clasificó como necesario para la seguridad o el funcionamiento del vehículo. Si no lo autorizas, la falla detectada puede seguir presente. Rechazarlo no significa que el vehículo esté en condiciones seguras; el taller evaluará cómo continuar y te informará.</p>
                            <label class="mt-2 flex items-start gap-2 text-seibi-ink">
                                <input type="checkbox" name="confirmar_riesgo[{{ $detailId }}]" value="1" x-model="risks[{{ $detailId }}]" :required="decisions[{{ $detailId }}] === 'rechazado'" class="mt-1 size-4 rounded border-seibi-border" @checked($oldRisks[$detailId] ?? false)>
                                <span>Leí la advertencia y decido no autorizar este concepto.</span>
                            </label>
                            @error('confirmar_riesgo.'.$detailId)<p class="mt-1.5 text-xs">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </article>
            @endforeach

            <noscript>
                <div class="rounded-panel border border-seibi-border bg-white p-4 text-sm">
                    <p class="text-slate-600">Tu navegador no ejecuta JavaScript, por lo que no se muestra el resumen automático. El taller calculará el importe con los conceptos que autorices.</p>
                    <label class="mt-3 flex items-start gap-2"><input type="checkbox" name="confirmacion_final" value="1" class="mt-1 size-4"> <span>Revisé los conceptos y confirmo mi decisión.</span></label>
                    <button type="submit" class="mt-3 w-full rounded-lg bg-seibi-orange px-4 py-3 text-sm font-semibold text-white">Registrar mi decisión</button>
                </div>
            </noscript>

            {{-- Resumen final: el importe es una vista previa; el servidor lo recalcula con los precios registrados. --}}
            <div x-cloak x-show="reviewing" x-transition.opacity class="fixed inset-0 z-50 flex items-end justify-center bg-seibi-navy/55 sm:items-center sm:p-4" @keydown.escape.window="reviewing = false" @click.self="reviewing = false">
                <section class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-panel" role="dialog" aria-modal="true" aria-labelledby="portal-summary-title" x-trap.noscroll="reviewing">
                    <h2 id="portal-summary-title" class="text-lg font-semibold text-seibi-ink">Resumen de tu decisión</h2>
                    <div class="mt-4 space-y-4 text-sm">
                        <div>
                            <p class="font-semibold text-status-success" x-text="'Autorizas (' + accepted.length + ')'"></p>
                            <ul class="mt-1 space-y-1">
                                <template x-for="item in accepted" :key="item.id">
                                    <li class="flex justify-between gap-3"><span class="min-w-0 break-words" x-text="item.label"></span><span class="shrink-0 font-medium" x-text="money(item.cents)"></span></li>
                                </template>
                                <li x-show="accepted.length === 0" class="text-slate-500">Ningún concepto.</li>
                            </ul>
                        </div>
                        <div>
                            <p class="font-semibold text-status-error" x-text="'Rechazas (' + rejected.length + ')'"></p>
                            <ul class="mt-1 space-y-1">
                                <template x-for="item in rejected" :key="item.id">
                                    <li class="flex justify-between gap-3"><span class="min-w-0 break-words" x-text="item.label + (item.necessary ? ' · necesario' : '')"></span><span class="shrink-0 text-slate-500" x-text="money(item.cents)"></span></li>
                                </template>
                                <li x-show="rejected.length === 0" class="text-slate-500">Ningún concepto.</li>
                            </ul>
                        </div>
                        <div class="flex items-center justify-between border-t border-seibi-border pt-3">
                            <span class="font-semibold">Importe que autorizarás</span>
                            <span class="text-lg font-bold text-seibi-ink" x-text="money(selectedCents) + ' MXN'"></span>
                        </div>
                        <p x-show="rejected.some(i => i.necessary)" class="rounded-lg bg-red-50 p-3 text-xs leading-5 text-status-error">Estás rechazando conceptos necesarios. El taller revisará tu decisión y te indicará cómo continuar.</p>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="confirmacion_final" value="1" :required="reviewing" class="mt-1 size-4 rounded border-seibi-border">
                            <span>Revisé el resumen y confirmo mi decisión. Entiendo que no podré cambiarla desde este enlace.</span>
                        </label>
                    </div>
                    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="min-h-11 rounded-lg border border-seibi-border px-4 text-sm font-semibold text-seibi-ink" @click="reviewing = false">Volver a revisar</button>
                        <button type="submit" class="min-h-11 rounded-lg bg-seibi-orange px-4 text-sm font-semibold text-white hover:bg-[#A94312]">Registrar mi decisión</button>
                    </div>
                </section>
            </div>
        </form>

        {{-- Barra fija con el importe seleccionado (vista previa). --}}
        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-seibi-border bg-white/95 backdrop-blur">
            <div class="mx-auto flex w-full max-w-2xl items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <p class="text-xs text-slate-500">Importe seleccionado</p>
                    <p class="text-lg font-bold text-seibi-ink"><span x-text="money(selectedCents)">$ 0.00</span> <span class="text-xs font-medium text-slate-500">de $ {{ number_format((float) $cotizacion->total, 2, '.', ',') }} MXN</span></p>
                    <p class="text-xs text-slate-500" x-show="pendingCount > 0" x-text="pendingCount + (pendingCount === 1 ? ' concepto sin decidir' : ' conceptos sin decidir')"></p>
                    <p class="text-xs text-status-error" x-cloak x-show="pendingCount === 0 && missingRiskCount > 0">Confirma las advertencias de los conceptos necesarios.</p>
                </div>
                <button type="button" class="min-h-11 shrink-0 rounded-lg bg-seibi-orange px-4 text-sm font-semibold text-white transition hover:bg-[#A94312] disabled:cursor-not-allowed disabled:opacity-50" :disabled="! canReview" @click="reviewing = true">Revisar decisión</button>
            </div>
        </div>
    </div>
@endsection
