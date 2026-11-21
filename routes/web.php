<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\CotizacionEnvioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticoController;
use App\Http\Controllers\EvidenciaController;
use App\Http\Controllers\OrdenServicioController;
use App\Http\Controllers\PartSearchController;
use App\Http\Controllers\PortalCotizacionController;
use App\Http\Controllers\SeguimientoReparacionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehiculoController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

// Portal público de cotizaciones: acceso solo mediante el token privado del correo; sin IDs en la ruta.
Route::middleware(['portal.headers', 'throttle:portal-cotizaciones'])->group(function () {
    Route::get('/c/{token}', [PortalCotizacionController::class, 'show'])->name('portal.cotizaciones.show');
    Route::post('/c/{token}/decision', [PortalCotizacionController::class, 'decide'])->name('portal.cotizaciones.decide');
});

// Fotografías publicadas del seguimiento: mismo token privado, identificadas por una referencia aleatoria (no por id).
Route::middleware(['portal.headers', 'throttle:portal-fotos'])->group(function () {
    Route::get('/c/{token}/fotos/{reference}', [PortalCotizacionController::class, 'photo'])->name('portal.cotizaciones.foto');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'active'])->group(function () {
    Route::middleware('role:administrador,mecanico,recepcionista')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('/clientes/buscar', [ClienteController::class, 'search'])->name('clientes.search');
        Route::get('/vehiculos', [VehiculoController::class, 'index'])->name('vehiculos.index');
        Route::get('/ordenes', [OrdenServicioController::class, 'index'])->name('ordenes.index');
    });

    Route::middleware('role:recepcionista')->group(function () {
        Route::get('/clientes/create', [ClienteController::class, 'create'])->name('clientes.create');
        Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
        Route::get('/clientes/{cliente}/edit', [ClienteController::class, 'edit'])->name('clientes.edit');
        Route::match(['PUT', 'PATCH'], '/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');

        Route::get('/vehiculos/create', [VehiculoController::class, 'create'])->name('vehiculos.create');
        Route::post('/vehiculos', [VehiculoController::class, 'store'])->name('vehiculos.store');
        Route::get('/vehiculos/{vehiculo}/edit', [VehiculoController::class, 'edit'])->name('vehiculos.edit');
        Route::match(['PUT', 'PATCH'], '/vehiculos/{vehiculo}', [VehiculoController::class, 'update'])->name('vehiculos.update');

        Route::get('/ordenes/create', [OrdenServicioController::class, 'create'])->name('ordenes.create');
        Route::post('/ordenes', [OrdenServicioController::class, 'store'])->name('ordenes.store');
        Route::get('/ordenes/{orden}/edit', [OrdenServicioController::class, 'edit'])->name('ordenes.edit');
        Route::match(['PUT', 'PATCH'], '/ordenes/{orden}', [OrdenServicioController::class, 'update'])->name('ordenes.update');
    });

    Route::middleware('role:administrador,mecanico,recepcionista')->group(function () {
        Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
        Route::get('/vehiculos/{vehiculo}', [VehiculoController::class, 'show'])->name('vehiculos.show');
        Route::get('/ordenes/{orden}', [OrdenServicioController::class, 'show'])->name('ordenes.show');
        Route::get('/ordenes/{orden}/evidencias/{evidencia}', [EvidenciaController::class, 'show'])->name('ordenes.evidencias.show');
        Route::get('/ordenes/{orden}/diagnosticos/estado/{envio_uuid}', [DiagnosticoController::class, 'submissionStatus'])
            ->name('diagnosticos.submission-status');
    });

    Route::middleware('role:mecanico')->group(function () {
        Route::post('/ordenes/{orden}/iniciar-diagnostico', [OrdenServicioController::class, 'iniciarDiagnostico'])
            ->name('ordenes.iniciar-diagnostico');
        Route::get('/ordenes/{orden}/cotizaciones/create', [CotizacionController::class, 'create'])
            ->name('cotizaciones.create');
        Route::post('/ordenes/{orden}/cotizaciones', [CotizacionController::class, 'store'])
            ->name('cotizaciones.store');
        Route::get('/ordenes/{orden}/cotizaciones/{cotizacion}/edit', [CotizacionController::class, 'edit'])
            ->name('cotizaciones.edit');
        Route::match(['PUT', 'PATCH'], '/ordenes/{orden}/cotizaciones/{cotizacion}', [CotizacionController::class, 'update'])
            ->name('cotizaciones.update');
        Route::delete('/ordenes/{orden}/cotizaciones/{cotizacion}', [CotizacionController::class, 'destroy'])
            ->name('cotizaciones.destroy');
        Route::post('/ordenes/{orden}/cotizaciones/{cotizacion}/enviar', [CotizacionEnvioController::class, 'send'])
            ->name('cotizaciones.enviar');
        Route::post('/ordenes/{orden}/cotizaciones/{cotizacion}/reenviar', [CotizacionEnvioController::class, 'resend'])
            ->name('cotizaciones.reenviar');
        Route::post('/ordenes/{orden}/cotizaciones/{cotizacion}/nueva-version', [CotizacionEnvioController::class, 'createVersion'])
            ->name('cotizaciones.nueva-version');
        Route::post('/ordenes/{orden}/cotizaciones/{cotizacion}/seguimiento', [CotizacionEnvioController::class, 'followUp'])
            ->name('cotizaciones.seguimiento');
        Route::get('/ordenes/{orden}/autopartes/buscar', [PartSearchController::class, 'search'])
            ->name('autopartes.search');
        Route::get('/ordenes/{orden}/autopartes/oferta/detalles', [PartSearchController::class, 'offerDetails'])
            ->name('autopartes.details');
        Route::post('/ordenes/{orden}/cotizaciones/{cotizacion}/autopartes/agregar', [PartSearchController::class, 'addOffer'])
            ->name('autopartes.add');
        Route::post('/ordenes/{orden}/seguimiento/avances', [SeguimientoReparacionController::class, 'storeUpdate'])
            ->name('seguimiento.avances.store');
        Route::post('/ordenes/{orden}/seguimiento/revision-final', [SeguimientoReparacionController::class, 'storeFinalReview'])
            ->name('seguimiento.revision-final.store');
        Route::post('/ordenes/{orden}/seguimiento/avances/{actualizacion}/publicar', [SeguimientoReparacionController::class, 'publish'])
            ->name('seguimiento.avances.publicar');
        Route::post('/ordenes/{orden}/seguimiento/etapa', [SeguimientoReparacionController::class, 'transition'])
            ->name('seguimiento.etapa');
        Route::post('/ordenes/{orden}/seguimiento/accesos/{acceso}/revocar', [SeguimientoReparacionController::class, 'revokeAccess'])
            ->name('seguimiento.accesos.revocar');
        Route::get('/ordenes/{orden}/diagnosticos/create', [DiagnosticoController::class, 'create'])
            ->name('diagnosticos.create');
        Route::post('/ordenes/{orden}/diagnosticos', [DiagnosticoController::class, 'store'])
            ->name('diagnosticos.store');
        Route::post('/ordenes/{orden}/diagnosticos/confirmar-nuevo/{token}', [DiagnosticoController::class, 'confirmNew'])
            ->name('diagnosticos.confirm-new');
        Route::post('/ordenes/{orden}/diagnosticos/descartar-cambios/{token}/{diagnostico}', [DiagnosticoController::class, 'discardChanges'])
            ->name('diagnosticos.discard-changes');
        Route::get('/ordenes/{orden}/diagnosticos/{diagnostico}/edit', [DiagnosticoController::class, 'edit'])
            ->name('diagnosticos.edit');
        Route::match(['PUT', 'PATCH'], '/ordenes/{orden}/diagnosticos/{diagnostico}', [DiagnosticoController::class, 'update'])
            ->name('diagnosticos.update');
    });

    Route::middleware('role:administrador,mecanico,recepcionista')->group(function () {
        Route::get('/ordenes/{orden}/cotizaciones', [CotizacionController::class, 'index'])->name('cotizaciones.index');
        Route::get('/ordenes/{orden}/cotizaciones/{cotizacion}', [CotizacionController::class, 'show'])->name('cotizaciones.show');
    });

    Route::middleware('role:administrador')->group(function () {
        Route::get('/usuarios', [UserController::class, 'index'])->name('usuarios.index');
        Route::get('/usuarios/create', [UserController::class, 'create'])->name('usuarios.create');
        Route::post('/usuarios', [UserController::class, 'store'])->name('usuarios.store');
        Route::get('/usuarios/{usuario}/edit', [UserController::class, 'edit'])->name('usuarios.edit');
        Route::match(['PUT', 'PATCH'], '/usuarios/{usuario}', [UserController::class, 'update'])->name('usuarios.update');
        Route::patch('/usuarios/{usuario}/activo', [UserController::class, 'toggleActive'])->name('usuarios.toggle-active');
    });
});
