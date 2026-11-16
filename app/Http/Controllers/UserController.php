<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $users = User::query()
            ->with('role')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('usuarios.index', ['users' => $users, 'search' => $search]);
    }

    public function create(): View
    {
        return view('usuarios.create', ['roles' => $this->availableRoles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'id_rol' => ['required', 'integer', Rule::exists('roles', 'id_rol')->whereIn('nombre', ['administrador', 'mecanico', 'recepcionista'])],
            'activo' => ['nullable', 'boolean'],
        ]);

        $user = new User;
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->password = $validated['password'];
        $user->id_rol = $validated['id_rol'];
        $user->activo = $request->boolean('activo', true);
        $user->save();

        return redirect()->route('usuarios.index')->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $usuario): View
    {
        $usuario->load('role');

        return view('usuarios.edit', [
            'usuario' => $usuario,
            'roles' => $this->availableRoles(),
        ]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario->getKey())],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'id_rol' => ['required', 'integer', Rule::exists('roles', 'id_rol')->whereIn('nombre', ['administrador', 'mecanico', 'recepcionista'])],
            'activo' => ['required', 'boolean'],
        ]);

        if ($usuario->is($request->user()) && ! $request->boolean('activo')) {
            return back()->withErrors(['activo' => 'No puedes desactivar tu propia cuenta administrativa.']);
        }

        $newRole = Role::query()->findOrFail($validated['id_rol']);
        if ($this->wouldRemoveLastActiveAdministrator($usuario, $newRole, $request->boolean('activo'))) {
            return back()->withErrors(['activo' => 'Debe existir al menos un administrador activo.']);
        }

        $usuario->name = $validated['name'];
        $usuario->email = $validated['email'];
        $usuario->id_rol = $validated['id_rol'];
        $usuario->activo = $request->boolean('activo');
        if (! empty($validated['password'])) {
            $usuario->password = $validated['password'];
        }
        $usuario->save();

        return redirect()->route('usuarios.index')->with('success', 'Usuario actualizado correctamente.');
    }

    public function toggleActive(Request $request, User $usuario): RedirectResponse
    {
        if ($usuario->is($request->user()) && $usuario->activo) {
            return back()->withErrors(['activo' => 'No puedes desactivar tu propia cuenta administrativa.']);
        }

        if (! $usuario->activo) {
            $usuario->activo = true;
            $usuario->save();

            return back()->with('success', 'Usuario activado.');
        }

        if ($this->isLastActiveAdministrator($usuario)) {
            return back()->withErrors(['activo' => 'Debe existir al menos un administrador activo.']);
        }

        $usuario->activo = false;
        $usuario->save();

        return back()->with('success', 'Usuario desactivado.');
    }

    private function availableRoles()
    {
        return Role::query()
            ->whereIn('nombre', ['administrador', 'mecanico', 'recepcionista'])
            ->orderBy('nombre')
            ->get();
    }

    private function wouldRemoveLastActiveAdministrator(User $usuario, Role $newRole, bool $newActive): bool
    {
        $usuario->loadMissing('role');
        if ($usuario->role?->nombre !== 'administrador' || $usuario->activo === false) {
            return false;
        }
        if ($newRole->nombre === 'administrador' && $newActive) {
            return false;
        }

        return $this->isLastActiveAdministrator($usuario);
    }

    private function isLastActiveAdministrator(User $usuario): bool
    {
        $usuario->loadMissing('role');
        if ($usuario->role?->nombre !== 'administrador' || ! $usuario->activo) {
            return false;
        }

        return ! User::query()
            ->where('activo', true)
            ->where('id', '!=', $usuario->getKey())
            ->whereHas('role', fn ($query) => $query->where('nombre', 'administrador'))
            ->exists();
    }
}
