<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Vehiculo;
use Illuminate\Http\Request;

class VehiculoController extends Controller
{
    public function index(Request $request)
    {
        $query = Vehiculo::with('cliente');

        if ($q = $request->input('q')) {
            $query->where(function ($builder) use ($q) {
                $builder->where('marca', 'like', "%{$q}%")
                    ->orWhere('modelo', 'like', "%{$q}%")
                    ->orWhere('placas', 'like', "%{$q}%");
            });
        }

        if ($idCliente = $request->input('cliente_id')) {
            $query->where('id_cliente', $idCliente);
        }

        $vehiculos = $query->orderBy('fecha_registro', 'desc')->paginate(15)->withQueryString();
        $clientes = Cliente::orderBy('apellido')->orderBy('nombre')->get(['id_cliente', 'nombre', 'apellido']);

        return view('vehiculos.index', compact('vehiculos', 'clientes'));
    }

    /** Pantallas desde las que se puede abrir «Nuevo vehículo» y a las que se regresa al guardarlo. */
    private const ORIGINS = ['cliente', 'recepcion'];

    public function create(Request $request)
    {
        // Desde el perfil del cliente o desde una recepción el cliente ya está decidido: se muestra fijo.
        $lockedClient = $request->integer('cliente_id')
            ? Cliente::query()->find($request->integer('cliente_id'), ['id_cliente', 'nombre', 'apellido', 'telefono'])
            : null;
        $clientes = $lockedClient === null
            ? Cliente::orderBy('apellido')->orderBy('nombre')->get(['id_cliente', 'nombre', 'apellido'])
            : collect();
        $origen = in_array($request->query('origen'), self::ORIGINS, true) ? $request->query('origen') : null;

        return view('vehiculos.create', [
            'clientes' => $clientes,
            'lockedClient' => $lockedClient,
            'origen' => $origen,
            'years' => self::yearOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_cliente' => 'required|exists:clientes,id_cliente',
            'marca' => 'required|string|max:60',
            'modelo' => 'required|string|max:80',
            'anio' => 'required|integer|min:1900|max:'.(date('Y') + 1),
            'version' => 'nullable|string|max:100',
            'placas' => 'nullable|string|max:15',
            'numero_serie' => 'nullable|string|max:50',
            'kilometraje_actual' => 'nullable|integer|min:0',
        ], [
            'id_cliente.required' => 'Debe seleccionar un cliente.',
            'id_cliente.exists' => 'El cliente seleccionado no existe.',
            'marca.required' => 'La marca es obligatoria.',
            'marca.max' => 'La marca no puede exceder 60 caracteres.',
            'modelo.required' => 'El modelo es obligatorio.',
            'modelo.max' => 'El modelo no puede exceder 80 caracteres.',
            'anio.required' => 'El año es obligatorio.',
            'anio.integer' => 'El año debe ser un número entero.',
            'anio.min' => 'El año no puede ser menor a 1900.',
            'anio.max' => 'El año no puede ser mayor a '.(date('Y') + 1).'.',
            'version.max' => 'La versión no puede exceder 100 caracteres.',
            'placas.max' => 'Las placas no pueden exceder 15 caracteres.',
            'numero_serie.max' => 'El número de serie no puede exceder 50 caracteres.',
            'kilometraje_actual.integer' => 'El kilometraje debe ser un número entero.',
            'kilometraje_actual.min' => 'El kilometraje no puede ser negativo.',
        ]);

        $vehiculo = Vehiculo::create($validated);

        return match ($request->input('origen')) {
            'recepcion' => redirect()->route('ordenes.create', ['cliente_id' => $vehiculo->id_cliente])
                ->with('success', 'Vehículo registrado. Ya puedes seleccionarlo para la recepción.'),
            'cliente' => redirect()->route('clientes.show', $vehiculo->id_cliente)
                ->with('success', 'Vehículo registrado exitosamente.'),
            default => redirect()->route('vehiculos.show', $vehiculo)
                ->with('success', 'Vehículo registrado exitosamente.'),
        };
    }

    public function show(Vehiculo $vehiculo)
    {
        $vehiculo->load('cliente');

        return view('vehiculos.show', compact('vehiculo'));
    }

    public function edit(Vehiculo $vehiculo)
    {
        $clientes = Cliente::orderBy('apellido')->orderBy('nombre')->get(['id_cliente', 'nombre', 'apellido']);

        return view('vehiculos.edit', [
            'vehiculo' => $vehiculo,
            'clientes' => $clientes,
            'years' => self::yearOptions((int) $vehiculo->anio),
        ]);
    }

    /**
     * Años seleccionables, del más reciente (año próximo, por modelos adelantados) al más antiguo.
     * Si el vehículo ya tiene un año fuera del rango se agrega para no perderlo al editar.
     *
     * @return list<int>
     */
    private static function yearOptions(?int $current = null): array
    {
        $years = range((int) date('Y') + 1, 1950);
        if ($current !== null && $current > 0 && ! in_array($current, $years, true)) {
            $years[] = $current;
        }

        return $years;
    }

    public function update(Request $request, Vehiculo $vehiculo)
    {
        $validated = $request->validate([
            'id_cliente' => 'required|exists:clientes,id_cliente',
            'marca' => 'required|string|max:60',
            'modelo' => 'required|string|max:80',
            'anio' => 'required|integer|min:1900|max:'.(date('Y') + 1),
            'version' => 'nullable|string|max:100',
            'placas' => 'nullable|string|max:15',
            'numero_serie' => 'nullable|string|max:50',
            'kilometraje_actual' => 'nullable|integer|min:0',
        ], [
            'id_cliente.required' => 'Debe seleccionar un cliente.',
            'id_cliente.exists' => 'El cliente seleccionado no existe.',
            'marca.required' => 'La marca es obligatoria.',
            'marca.max' => 'La marca no puede exceder 60 caracteres.',
            'modelo.required' => 'El modelo es obligatorio.',
            'modelo.max' => 'El modelo no puede exceder 80 caracteres.',
            'anio.required' => 'El año es obligatorio.',
            'anio.integer' => 'El año debe ser un número entero.',
            'anio.min' => 'El año no puede ser menor a 1900.',
            'anio.max' => 'El año no puede ser mayor a '.(date('Y') + 1).'.',
            'version.max' => 'La versión no puede exceder 100 caracteres.',
            'placas.max' => 'Las placas no pueden exceder 15 caracteres.',
            'numero_serie.max' => 'El número de serie no puede exceder 50 caracteres.',
            'kilometraje_actual.integer' => 'El kilometraje debe ser un número entero.',
            'kilometraje_actual.min' => 'El kilometraje no puede ser negativo.',
        ]);

        $vehiculo->update($validated);

        return redirect()->route('vehiculos.show', $vehiculo)
            ->with('success', 'Vehículo actualizado exitosamente.');
    }
}
