{{-- Bitácora de la cotización (eventos_cotizacion). Muestra solo datos operativos; nunca contiene el token. --}}
@php
    $eventTones = [
        'enviada' => 'info', 'reenviada' => 'info', 'consultada' => 'neutral', 'autorizada' => 'success',
        'rechazada' => 'error', 'vencida' => 'neutral', 'sustituida' => 'neutral', 'revocada' => 'neutral',
        'version_creada' => 'neutral', 'seguimiento_registrado' => 'pending', 'envio_aceptado' => 'success', 'envio_fallido' => 'error',
        'integridad_invalida' => 'error',
    ];
@endphp
<x-ui.panel title="Historial de la cotización" :description="$cotizacion->eventos->count().' evento(s) registrados'">
    @if ($cotizacion->eventos->isEmpty())
        <p class="text-sm text-slate-500">Sin eventos. Se registrarán al enviar la cotización al cliente.</p>
    @else
        <ol class="space-y-3">
            @foreach ($cotizacion->eventos as $event)
                @php $data = $event->datos ?? []; @endphp
                <li class="rounded-lg border border-seibi-border p-3 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <x-ui.badge :tone="$eventTones[$event->tipo] ?? 'neutral'">{{ $event->typeLabel() }}</x-ui.badge>
                        <span class="text-xs text-slate-500">{{ \App\Models\Cotizacion::formatForDisplay($event->fecha) }} · {{ $event->usuario?->name ?? ($event->id_acceso ? 'Cliente (enlace privado)' : 'Sistema') }}</span>
                    </div>
                    <div class="mt-2 space-y-0.5 text-xs text-slate-600">
                        @if (! empty($data['correo_destino']))<p>Correo: {{ $data['correo_destino'] }}</p>@endif
                        @if (! empty($data['modo_correo']))<p>Modo de correo: {{ ['simulado' => 'simulación (no entregado al cliente)', 'prueba_local' => 'SMTP real · prueba local', 'real' => 'SMTP real'][$data['modo_correo']] ?? $data['modo_correo'] }}</p>@endif
                        @if (! empty($data['id_mensaje']))<p>Id del mensaje en el servidor SMTP: {{ $data['id_mensaje'] }} (aceptado, no confirma la bandeja de entrada)</p>@endif
                        @if (! empty($data['categoria']))<p class="text-status-error">Causa: {{ \App\Services\Cotizaciones\QuotationDispatchService::FAILURE_CATEGORIES[$data['categoria']] ?? $data['categoria'] }}@if (! empty($data['codigo_smtp'])) (código SMTP {{ $data['codigo_smtp'] }})@endif</p>@endif
                        @if (! empty($data['vence']))<p>Vence: {{ \App\Models\Cotizacion::formatForDisplay(\Illuminate\Support\Carbon::parse($data['vence'])) }}</p>@endif
                        @if (! empty($data['resultado']))<p>Resultado: {{ \App\Models\Cotizacion::STATUSES[$data['resultado']]['label'] ?? $data['resultado'] }} · importe autorizado $ {{ number_format((float) ($data['importe_autorizado'] ?? 0), 2, '.', ',') }}</p>@endif
                        @if (! empty($data['necesarios_rechazados']))<p class="text-status-error">Conceptos necesarios rechazados: {{ is_array($data['necesarios_rechazados']) ? count($data['necesarios_rechazados']) : 0 }}</p>@endif
                        @if (! empty($data['accion']))<p class="font-medium text-seibi-ink">{{ \App\Models\EventoCotizacion::FOLLOW_UP_ACTIONS[$data['accion']] ?? $data['accion'] }}</p>@endif
                        @if (! empty($data['nota']))<p class="whitespace-pre-line">{{ $data['nota'] }}</p>@endif
                        @if (! empty($data['motivo']))<p>Motivo: {{ $data['motivo'] }}</p>@endif
                        @if (! empty($data['sustituida_por']))<p>Sustituida por {{ $data['sustituida_por'] }}</p>@endif
                        @if (! empty($data['folio']))<p>Nueva versión: {{ $data['folio'] }}</p>@endif
                        @if (! empty($data['error']))<p class="text-status-error">Error: {{ $data['error'] }}@if (! empty($data['detalle'])) — {{ $data['detalle'] }}@endif</p>@endif
                        @if (! empty($data['contexto']))<p>Detectado durante: {{ ['consulta' => 'consulta del cliente', 'autorizacion' => 'autorización del cliente', 'reenvio' => 'reenvío'][$data['contexto']] ?? $data['contexto'] }}</p>@endif
                        @if (! empty($data['origen']) && $event->tipo === 'vencida')<p>Detectado por: {{ $data['origen'] === 'comando' ? 'tarea programada' : 'acceso al enlace' }}</p>@endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</x-ui.panel>
