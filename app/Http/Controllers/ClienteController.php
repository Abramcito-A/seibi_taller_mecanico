<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $query = Cliente::query();

        $search = trim((string) $request->string('q'));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $this->applyClientSearch($builder, $search);
            });
        }

        $clientes = $query->orderBy('fecha_registro', 'desc')->paginate(15)->withQueryString();

        return view('clientes.index', compact('clientes'));
    }

    public function search(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());
        if ($search === '') {
            return response()->json(['data' => []]);
        }

        $clientes = Cliente::query()
            ->where(function (Builder $builder) use ($search): void {
                $this->applyClientSearch($builder, $search);
            })
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id_cliente', 'nombre', 'apellido', 'telefono', 'correo']);

        return response()->json([
            'data' => $clientes->map(fn (Cliente $cliente): array => [
                'id' => $cliente->id_cliente,
                'nombre' => $cliente->nombre,
                'apellido' => $cliente->apellido,
                'nombre_completo' => trim($cliente->nombre.' '.$cliente->apellido),
                'telefono' => $cliente->telefono,
                'correo' => $cliente->correo,
            ]),
        ]);
    }

    private function applyClientSearch(Builder $query, string $search): void
    {
        $normalizedSearch = preg_replace('/\s+/', ' ', trim($search)) ?? trim($search);
        $terms = preg_split('/\s+/', $normalizedSearch, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pattern = '%'.$normalizedSearch.'%';

        $query->where(function (Builder $fullNameQuery) use ($pattern): void {
            $fullNameQuery->where('nombre', 'like', $pattern)
                ->orWhere('apellido', 'like', $pattern)
                ->orWhere('telefono', 'like', $pattern)
                ->orWhere('correo', 'like', $pattern)
                ->orWhereRaw("CONCAT(nombre, ' ', apellido) LIKE ?", [$pattern])
                ->orWhereRaw("CONCAT(apellido, ' ', nombre) LIKE ?", [$pattern]);
        });

        foreach ($terms as $term) {
            $termPattern = '%'.$term.'%';
            $query->where(function (Builder $termQuery) use ($termPattern): void {
                $termQuery->where('nombre', 'like', $termPattern)
                    ->orWhere('apellido', 'like', $termPattern)
                    ->orWhere('telefono', 'like', $termPattern)
                    ->orWhere('correo', 'like', $termPattern);
            });
        }
    }

    public function create()
    {
        return view('clientes.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'telefono' => 'required|string|max:20',
            'correo' => 'nullable|email|max:150',
            'direccion' => 'nullable|string|max:255',
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede exceder 100 caracteres.',
            'apellido.required' => 'El apellido es obligatorio.',
            'apellido.max' => 'El apellido no puede exceder 100 caracteres.',
            'telefono.required' => 'El teléfono es obligatorio.',
            'telefono.max' => 'El teléfono no puede exceder 20 caracteres.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'correo.max' => 'El correo no puede exceder 150 caracteres.',
            'direccion.max' => 'La dirección no puede exceder 255 caracteres.',
        ]);

        $cliente = Cliente::create($validated);

        return redirect()->route('clientes.show', $cliente)
            ->with('success', 'Cliente registrado exitosamente.');
    }

    public function show(Cliente $cliente)
    {
        $cliente->load('vehiculos');

        return view('clientes.show', compact('cliente'));
    }

    public function edit(Cliente $cliente)
    {
        return view('clientes.edit', compact('cliente'));
    }

    public function update(Request $request, Cliente $cliente)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'telefono' => 'required|string|max:20',
            'correo' => 'nullable|email|max:150',
            'direccion' => 'nullable|string|max:255',
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede exceder 100 caracteres.',
            'apellido.required' => 'El apellido es obligatorio.',
            'apellido.max' => 'El apellido no puede exceder 100 caracteres.',
            'telefono.required' => 'El teléfono es obligatorio.',
            'telefono.max' => 'El teléfono no puede exceder 20 caracteres.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'correo.max' => 'El correo no puede exceder 150 caracteres.',
            'direccion.max' => 'La dirección no puede exceder 255 caracteres.',
        ]);

        $cliente->update($validated);

        return redirect()->route('clientes.show', $cliente)
            ->with('success', 'Cliente actualizado exitosamente.');
    }
}
