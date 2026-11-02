<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticoController;
use App\Http\Controllers\EvidenciaController;
use App\Http\Controllers\OrdenServicioController;
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

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'active'])->group(function () {
    Route::middleware('role:administrador,mecanico,recepcionista')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('/vehiculos', [VehiculoController::class, 'index'])->name('vehiculos.index');
        Route::get('/ordenes', [OrdenServicioController::class, 'index'])->name('ordenes.index');
    });

    Route::middleware('role:administrador,recepcionista')->group(function () {
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

    Route::middleware('role:administrador,mecanico')->group(function () {
        Route::post('/ordenes/{orden}/iniciar-diagnostico', [OrdenServicioController::class, 'iniciarDiagnostico'])
            ->name('ordenes.iniciar-diagnostico');
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

    Route::middleware('role:administrador')->group(function () {
        Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');
        Route::delete('/vehiculos/{vehiculo}', [VehiculoController::class, 'destroy'])->name('vehiculos.destroy');
    });
});
