<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Signature('seibi:user:create {--inactive : Crear la cuenta desactivada}')]
#[Description('Crear una cuenta interna de SEIBI')]
class CreateInternalUser extends Command
{
    public function handle(): int
    {
        $roles = Role::query()->orderBy('nombre')->pluck('nombre')->all();

        if ($roles === []) {
            $this->error('No hay roles disponibles. Ejecuta php artisan db:seed --class=RoleSeeder.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nombre completo'));
        $email = Str::lower(trim((string) $this->ask('Correo electrónico')));
        $roleName = $this->choice('Rol', $roles);
        $password = $this->secret('Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas, números y símbolos)');
        $passwordConfirmation = $this->secret('Confirma la contraseña');

        if (! is_string($password) || ! is_string($passwordConfirmation) || ! hash_equals($password, $passwordConfirmation)) {
            $this->error('Las contraseñas no coinciden. No se creó la cuenta.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'role' => $roleName,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'string', Rule::exists('roles', 'nombre')],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
        ], [
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'password.min' => 'La contraseña debe tener al menos 12 caracteres.',
            'password.mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
            'password.symbols' => 'La contraseña debe incluir al menos un símbolo.',
            'password.password.mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
            'password.password.numbers' => 'La contraseña debe incluir al menos un número.',
            'password.password.symbols' => 'La contraseña debe incluir al menos un símbolo.',
            'role.exists' => 'El rol seleccionado ya no existe.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::query()->where('nombre', $roleName)->first();

        if (! $role instanceof Role) {
            $this->error('El rol seleccionado ya no existe. No se creó la cuenta.');

            return self::FAILURE;
        }

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->id_rol = $role->id_rol;
        $user->activo = ! $this->option('inactive');
        $user->password = Hash::make($password);
        $user->save();

        $this->info("Cuenta creada para {$user->email} con rol {$role->nombre}. Estado: ".($user->activo ? 'activa' : 'inactiva').'.');

        return self::SUCCESS;
    }
}
