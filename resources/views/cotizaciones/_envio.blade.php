{{-- Envío al cliente, decisión registrada y seguimiento del mecánico. Solo el mecánico ve las acciones. --}}
@php
    $isMechanic = auth()->user()->hasRole('mecanico');
    $cliente = $orden->vehiculo->cliente;
    $details = $cotizacion->detalles;
    $unclassified = $details->filter(fn ($detail) => $detail->prioridad === null);
    $accepted = $details->where('decision_cliente', 'aceptado');
    $rejected = $details->where('decision_cliente', 'rechazado');
    $necessaryRejected = $rejected->filter(fn ($detail) => $detail->isNecessary());
    $isDraft = $cotizacion->isEditableDraft();
    $canResend = $cotizacion->isAwaitingDecision();
    $canCreateVersion = ! $isDraft && $cotizacion->estado !== 'sustituida' && $cotizacion->siguienteVersion === null;
    $canFollowUp = $cotizacion->hasClientDecision() && $cotizacion->estado !== 'sustituida';
    $failureCategory = $lastDelivery?->datos['categoria'] ?? null;
    $isLocalTest = $mailStatus['mode'] === \App\Services\Mail\MailDeliveryGuard::MODE_LOCAL_TEST;
    $recipientMismatch = $isLocalTest && $cliente->correo && strtolower(trim($cliente->correo)) !== $mailStatus['test_recipient'];
    $sendMessage = $isLocalTest
        ? 'Se enviará un correo REAL por SMTP (Brevo, modo de prueba local) con un enlace privado a '.($cliente->correo ?: 'el cliente').'. Después del envío la cotización ya no podrá editarse; los cambios requerirán una nueva versión.'
        : 'Se enviará un enlace privado a '.($cliente->correo ?: 'el cliente').'. Después del envío la cotización ya no podrá editarse; los cambios requerirán una nueva versión.';
    $deliveryLabel = match (true) {
        $lastDelivery === null => null,
        $lastDelivery->tipo === 'envio_fallido' => ['Rechazado: '.(\App\Services\Cotizaciones\QuotationDispatchService::FAILURE_CATEGORIES[$failureCategory] ?? 'el correo no pudo entregarse al servidor de correo.').' Usa «Reenviar» cuando esté corregido.', 'error'],
        ($lastDelivery->datos['modo_correo'] ?? null) === \App\Services\Mail\MailDeliveryGuard::MODE_SIMULATED => ['Simulado: el correo se escribió en el log del servidor y no se entregó al cliente.', 'warning'],
        $lastDelivery->tipo === 'envio_aceptado' => ['Aceptado por el servidor SMTP'.(! empty($lastDelivery->datos['id_mensaje']) ? ' (id '.$lastDelivery->datos['id_mensaje'].')' : '').'. Eso no confirma que llegó a la bandeja de entrada; revisa Spam y el registro de Brevo.', 'success'],
        // Envíos registrados antes de que existiera el evento envio_aceptado.
        default => ['Registrado como enviado, sin confirmación del servidor SMTP en la bitácora.', 'info'],
    };
@endphp

<x-ui.panel title="Envío y autorización del cliente" description="El estado de la cotización es independiente del estado de la orden de servicio.">
    <div class="space-y-4">
        @if ($errors->has('envio') || $errors->has('version'))
            <x-ui.alert variant="error">
                <ul class="space-y-1">
                    @foreach (array_merge($errors->get('envio'), $errors->get('version')) as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($mailStatus['mode'] === \App\Services\Mail\MailDeliveryGuard::MODE_SIMULATED)
            <x-ui.alert variant="warning">
                <p class="font-semibold">Correo en modo simulación (driver «{{ $mailStatus['transport'] }}»)</p>
                <p class="mt-1">Los correos se escriben en <code>storage/logs/laravel.log</code> y <strong>no se entregan al cliente</strong>. Configura un proveedor SMTP verificado para envíos reales.</p>
            </x-ui.alert>
        @elseif ($isLocalTest)
            <x-ui.alert variant="warning">
                <p class="font-semibold">Envío SMTP real · modo de prueba local</p>
                <p class="mt-1">Los correos salen de verdad por {{ config('mail.mailers.smtp.host') }} desde {{ config('mail.from.address') }}, pero <strong>solo se permite el destinatario de pruebas {{ $mailStatus['test_recipient'] }}</strong>. El dominio del remitente no tiene SPF/DKIM autenticados: el correo puede llegar a Spam. Este modo no existe fuera de APP_ENV=local.</p>
            </x-ui.alert>
        @elseif ($mailStatus['mode'] === \App\Services\Mail\MailDeliveryGuard::MODE_LIVE)
            <x-ui.alert variant="info">Envío SMTP real por {{ config('mail.mailers.smtp.host') }}.</x-ui.alert>
        @elseif ($mailStatus['mode'] === \App\Services\Mail\MailDeliveryGuard::MODE_BLOCKED)
            <x-ui.alert variant="error">
                <p class="font-semibold">El envío de correo está bloqueado por la configuración</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-4">
                    @foreach ($mailStatus['problems'] as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($integrityValid === false)
            <x-ui.alert variant="error">
                <p class="font-semibold">El contenido de esta cotización cambió después de enviarse</p>
                <p class="mt-1">La huella registrada no coincide con los conceptos actuales. El cliente no puede consultarla ni autorizarla. Revisa los datos y crea una nueva versión.</p>
            </x-ui.alert>
        @endif

        @if ($isDraft)
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Destinatario</dt><dd class="mt-1 break-all">{{ $cliente->correo ?: 'El cliente no tiene correo registrado' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Vigencia del enlace</dt><dd class="mt-1">{{ (int) config('seibi.quotation_link_ttl_days') }} días desde el envío</dd></div>
            </dl>
            @if ($unclassified->isNotEmpty())
                <x-ui.alert variant="warning">Antes de enviar, clasifica la prioridad de {{ $unclassified->count() }} concepto(s) desde «Editar borrador».</x-ui.alert>
            @endif
            @if ($recipientMismatch)
                <x-ui.alert variant="error">Modo de prueba local: el correo de este cliente no es el destinatario de pruebas ({{ $mailStatus['test_recipient'] }}). El envío se rechazará; usa una orden de un cliente de prueba con esa dirección.</x-ui.alert>
            @endif
            @if ($isMechanic)
                <form id="send-quotation-form" method="POST" action="{{ route('cotizaciones.enviar', [$orden, $cotizacion]) }}">@csrf</form>
                <x-ui.confirm-trigger form-id="send-quotation-form" variant="primary" title="Enviar cotización al cliente" :message="$sendMessage" confirm-label="Enviar">Enviar cotización</x-ui.confirm-trigger>
            @endif
        @else
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Estado</dt><dd class="mt-1"><x-ui.badge :tone="$cotizacion->statusTone()">{{ $cotizacion->statusLabel() }}</x-ui.badge></dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Versión</dt><dd class="mt-1">{{ $cotizacion->version }}@if ($cotizacion->origen) · deriva de <a class="font-semibold text-seibi-navy underline" href="{{ route('cotizaciones.show', [$orden, $cotizacion->origen]) }}">{{ $cotizacion->origen->folio }}</a>@endif</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Fecha de envío</dt><dd class="mt-1">{{ \App\Models\Cotizacion::formatForDisplay($cotizacion->fecha_envio) }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Vence</dt><dd class="mt-1">{{ \App\Models\Cotizacion::formatForDisplay($cotizacion->fecha_vencimiento) }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Correo de destino</dt><dd class="mt-1 break-all">{{ $latestAccess?->correo_destino ?? '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">Última consulta del cliente</dt><dd class="mt-1">{{ $latestAccess?->ultimo_acceso ? \App\Models\Cotizacion::formatForDisplay($latestAccess->ultimo_acceso) : 'Aún no abre el enlace' }}</dd></div>
            </dl>
            @if ($deliveryLabel)
                <x-ui.alert :variant="$deliveryLabel[1]">Último envío: {{ $deliveryLabel[0] }}</x-ui.alert>
            @endif
            @if ($cotizacion->siguienteVersion)
                <x-ui.alert variant="info">Existe la versión {{ $cotizacion->siguienteVersion->version }} (<a class="font-semibold underline" href="{{ route('cotizaciones.show', [$orden, $cotizacion->siguienteVersion]) }}">{{ $cotizacion->siguienteVersion->folio }}</a>, {{ $cotizacion->siguienteVersion->statusLabel() }}).@if ($cotizacion->siguienteVersion->isEditableDraft()) Al enviarla, esta versión quedará sustituida y su enlace se revocará.@endif</x-ui.alert>
            @endif

            @if ($isMechanic && ($canResend || $canCreateVersion))
                <div class="flex flex-wrap gap-2">
                    @if ($canResend)
                        <form id="resend-quotation-form" method="POST" action="{{ route('cotizaciones.reenviar', [$orden, $cotizacion]) }}">@csrf</form>
                        <x-ui.confirm-trigger form-id="resend-quotation-form" variant="secondary" title="Reenviar cotización" :message="'Se generará un enlace nuevo para '.($cliente->correo ?: 'el cliente').' y el anterior dejará de funcionar. La vigencia se reinicia.'" confirm-label="Reenviar">Reenviar cotización</x-ui.confirm-trigger>
                    @endif
                    @if ($canCreateVersion)
                        <form id="version-quotation-form" method="POST" action="{{ route('cotizaciones.nueva-version', [$orden, $cotizacion]) }}">@csrf</form>
                        <x-ui.confirm-trigger form-id="version-quotation-form" variant="neutral" title="Crear nueva versión" message="Se copiará esta cotización a un borrador nuevo. Esta versión se conserva sin cambios y quedará sustituida cuando envíes la nueva, que requerirá una nueva autorización del cliente." confirm-label="Crear versión">Crear nueva versión</x-ui.confirm-trigger>
                    @endif
                </div>
            @endif
        @endif

        @if ($cotizacion->hasClientDecision())
            <div class="space-y-3 border-t border-seibi-border pt-4">
                <h3 class="text-sm font-semibold text-seibi-ink">Resultado del cliente</h3>
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-semibold uppercase text-slate-500">Decisión</dt><dd class="mt-1 font-medium">{{ ['aprobada' => 'Autorizó todos los conceptos', 'parcial' => 'Autorización parcial', 'rechazada' => 'Rechazó todos los conceptos'][$cotizacion->decision_cliente] ?? $cotizacion->decision_cliente }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase text-slate-500">Fecha de decisión</dt><dd class="mt-1">{{ \App\Models\Cotizacion::formatForDisplay($cotizacion->fecha_decision) }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase text-slate-500">Importe autorizado</dt><dd class="mt-1 font-semibold">$ {{ number_format((float) $cotizacion->importe_autorizado, 2, '.', ',') }} <span class="font-normal text-slate-500">de $ {{ number_format((float) $cotizacion->total, 2, '.', ',') }} MXN</span></dd></div>
                </dl>
                @if ($cotizacion->decision_cliente === 'parcial')
                    <p class="text-xs text-slate-500">Autorización parcial: solo los conceptos marcados como autorizados pueden realizarse.</p>
                @endif
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-status-success/20 bg-emerald-50/50 p-3">
                        <p class="text-xs font-semibold uppercase text-status-success">Autorizados ({{ $accepted->count() }})</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            @forelse ($accepted as $detail)
                                <li class="flex justify-between gap-3"><span class="min-w-0 break-words">{{ $detail->descripcion }}</span><span class="shrink-0 font-medium">$ {{ number_format((float) $detail->importe, 2, '.', ',') }}</span></li>
                            @empty
                                <li class="text-slate-500">Ninguno.</li>
                            @endforelse
                        </ul>
                    </div>
                    <div class="rounded-lg border border-status-error/20 bg-red-50/50 p-3">
                        <p class="text-xs font-semibold uppercase text-status-error">Rechazados ({{ $rejected->count() }})</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            @forelse ($rejected as $detail)
                                <li class="flex justify-between gap-3"><span class="min-w-0 break-words">{{ $detail->descripcion }}@if ($detail->isNecessary()) <span class="text-xs font-semibold text-status-error">· necesario</span>@endif</span><span class="shrink-0 text-slate-500">$ {{ number_format((float) $detail->importe, 2, '.', ',') }}</span></li>
                            @empty
                                <li class="text-slate-500">Ninguno.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
                @if ($necessaryRejected->isNotEmpty())
                    <x-ui.alert variant="error">
                        <p class="font-semibold">El cliente rechazó {{ $necessaryRejected->count() }} concepto(s) necesario(s)</p>
                        <p class="mt-1">Confirmó la advertencia en el portal. Evalúa las implicaciones para la seguridad y el funcionamiento del vehículo antes de continuar; la autorización recibida no cubre esos trabajos ni acredita que el vehículo esté en condiciones seguras.</p>
                    </x-ui.alert>
                @endif

                @if ($isMechanic && $canFollowUp)
                    <form method="POST" action="{{ route('cotizaciones.seguimiento', [$orden, $cotizacion]) }}" class="space-y-3 rounded-lg border border-seibi-border p-4">
                        @csrf
                        <fieldset>
                            <legend class="text-sm font-semibold text-seibi-ink">Registrar seguimiento</legend>
                            <p class="mt-0.5 text-xs text-slate-500">Queda en la bitácora de la cotización. No cambia el estado de la orden de servicio.</p>
                            <div class="mt-2 space-y-2">
                                @foreach (\App\Models\EventoCotizacion::FOLLOW_UP_ACTIONS as $action => $label)
                                    @php $disabled = $action === 'continuar_autorizados' && $accepted->isEmpty(); @endphp
                                    <label class="flex items-start gap-2 text-sm {{ $disabled ? 'opacity-50' : '' }}">
                                        <input type="radio" name="accion" value="{{ $action }}" required @checked(old('accion') === $action) @disabled($disabled) class="mt-0.5">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('accion')<p class="mt-1 text-xs text-status-error">{{ $message }}</p>@enderror
                        </fieldset>
                        <label class="block space-y-1">
                            <span class="text-xs font-semibold text-slate-600">Nota (obligatoria)</span>
                            <textarea name="nota" rows="3" required minlength="5" maxlength="2000" class="w-full rounded-lg border border-seibi-border bg-white px-3 py-2 text-sm">{{ old('nota') }}</textarea>
                            @error('nota')<span class="block text-xs text-status-error">{{ $message }}</span>@enderror
                        </label>
                        <x-ui.button type="submit" variant="secondary">Guardar seguimiento</x-ui.button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</x-ui.panel>
