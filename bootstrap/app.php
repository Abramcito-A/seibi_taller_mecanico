<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetPortalSecurityHeaders;
use App\Http\Middleware\StartSessionWithoutPrivateUrls;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Solo se confía en un proxy que corre en la misma máquina (p. ej. cloudflared o un servidor web local), para que
        // detrás de un túnel HTTPS Laravel respete X-Forwarded-Proto/Host y genere enlaces y formularios con https.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureUserHasRole::class,
            'portal.headers' => SetPortalSecurityHeaders::class,
        ]);
        // El portal de cotizaciones lleva el token en la URL: no debe guardarse como "URL anterior" en la sesión.
        $middleware->replaceInGroup('web', StartSession::class, StartSessionWithoutPrivateUrls::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Sesión expirada (419) al confirmar una decisión en el portal: se vuelve a la cotización sin registrar nada.
        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() === 419 && $request->route()?->getName() === 'portal.cotizaciones.decide') {
                return redirect()
                    ->route('portal.cotizaciones.show', ['token' => (string) $request->route('token')])
                    ->with('portal_notice', 'La página estuvo abierta demasiado tiempo y, por seguridad, tu decisión no se registró. Revisa tu selección y confírmala de nuevo.');
            }

            return null;
        });
    })->create();
