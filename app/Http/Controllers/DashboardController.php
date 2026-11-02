<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use App\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleName = $user->role?->nombre;

        $counts = [
            'clients' => Cliente::query()->count(),
            'vehicles' => Vehiculo::query()->count(),
        ];

        if ($roleName === 'administrador') {
            $counts['orders'] = OrdenServicio::query()->count();
            $counts['quotes'] = Cotizacion::query()->count();
        }

        if ($roleName === 'mecanico') {
            $counts['diagnosisQueue'] = OrdenServicio::query()->where('estado', 'en_diagnostico')->count();
            $counts['repairQueue'] = OrdenServicio::query()->where('estado', 'en_reparacion')->count();
            $counts['ordersToDiagnose'] = OrdenServicio::query()->where('estado', 'recibida')->count();
        }

        if ($roleName === 'recepcionista') {
            $counts['receivedOrders'] = OrdenServicio::query()->where('estado', 'recibida')->count();
            $counts['readyForDelivery'] = OrdenServicio::query()->where('estado', 'lista_entrega')->count();
        }

        return view('dashboard', [
            'roleName' => $roleName,
            'counts' => $counts,
        ]);
    }
}
