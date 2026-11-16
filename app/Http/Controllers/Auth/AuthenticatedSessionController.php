<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'password.required' => 'Escribe tu contraseña.',
        ]);

        $email = Str::lower(trim($validated['email']));
        $key = $this->throttleKey($email, $request->ip() ?? '');

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withErrors(['login' => "Demasiados intentos. Espera {$seconds} segundos antes de intentarlo de nuevo."])
                ->onlyInput('email');
        }

        if (! Auth::attempt([
            'email' => $email,
            'password' => $validated['password'],
            'activo' => true,
        ])) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return back()
                ->withErrors(['login' => 'El correo o la contraseña no son válidos.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->to($this->intendedPath($request));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function throttleKey(string $email, string $ipAddress): string
    {
        return 'login:'.hash('sha256', $email.'|'.$ipAddress);
    }

    private function intendedPath(Request $request): string
    {
        $intendedUrl = $request->session()->pull('url.intended');

        if (! is_string($intendedUrl) || $intendedUrl === '' || str_contains($intendedUrl, '\\') || str_contains($intendedUrl, "\r") || str_contains($intendedUrl, "\n")) {
            return route('dashboard');
        }

        $parts = parse_url($intendedUrl);

        if ($parts === false || str_starts_with($intendedUrl, '//') || isset($parts['user']) || isset($parts['pass'])) {
            return route('dashboard');
        }

        if (isset($parts['host'])) {
            $applicationOrigin = parse_url((string) config('app.url'));
            $targetScheme = Str::lower($parts['scheme'] ?? '');
            $applicationScheme = Str::lower($applicationOrigin['scheme'] ?? '');
            $targetPort = $parts['port'] ?? ($targetScheme === 'https' ? 443 : 80);
            $applicationPort = $applicationOrigin['port'] ?? ($applicationScheme === 'https' ? 443 : 80);
            $sameOrigin = is_array($applicationOrigin)
                && Str::lower($parts['host']) === Str::lower($applicationOrigin['host'] ?? '')
                && $targetScheme === $applicationScheme
                && $targetPort === $applicationPort;

            if (! $sameOrigin) {
                return route('dashboard');
            }
        } elseif (isset($parts['scheme']) || isset($parts['port'])) {
            return route('dashboard');
        }

        $path = $parts['path'] ?? '/';

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return route('dashboard');
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
