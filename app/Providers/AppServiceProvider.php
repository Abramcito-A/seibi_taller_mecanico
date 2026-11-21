<?php

namespace App\Providers;

use App\Services\Mail\MailDeliveryGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Email;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Portal público de cotizaciones: limita por IP las consultas y, con más rigor, los envíos de decisión.
        RateLimiter::for('portal-cotizaciones', function (Request $request): array {
            $limits = [Limit::perMinute(30)->by('portal:'.$request->ip())];
            if ($request->isMethod('POST')) {
                $limits[] = Limit::perMinute(6)->by('portal-post:'.$request->ip());
            }

            return array_map(fn (Limit $limit): Limit => $limit->response(fn () => response()->view('portal.acceso-invalido', [
                'title' => 'Demasiados intentos',
                'message' => 'Se recibieron demasiadas solicitudes desde tu conexión. Espera un minuto y vuelve a abrir el enlace.',
            ], 429)), $limits);
        });

        // Fotografías del seguimiento en el portal: una página puede cargar varias, así que el límite es propio y más amplio.
        RateLimiter::for('portal-fotos', fn (Request $request): Limit => Limit::perMinute(120)->by('portal-fotos:'.$request->ip()));

        // Desarrollo local con SMTP real: ningún correo (de cotizaciones o de cualquier otra parte del sistema) sale hacia
        // una dirección distinta de SEIBI_TEST_RECIPIENT. Devolver false cancela el envío; Mail::send() devuelve null.
        Event::listen(MessageSending::class, function (MessageSending $event): ?bool {
            if (! $event->message instanceof Email) {
                return null;
            }

            $violations = $this->app->make(MailDeliveryGuard::class)->messageViolations($event->message);
            if ($violations === []) {
                return null;
            }

            Log::warning('Correo cancelado en desarrollo local: solo se permite entregar a SEIBI_TEST_RECIPIENT con el remitente de MAIL_FROM_ADDRESS.', [
                'motivos' => $violations,
            ]);

            return false;
        });
    }
}
