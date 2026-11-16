<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('visitors are sent to login from the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('visitors cannot access customer or vehicle records', function (string $path) {
    $this->get($path)->assertRedirect(route('login'));
})->with([
    'customers' => '/clientes',
    'vehicles' => '/vehiculos',
]);

test('valid credentials authenticate active staff and redirect to the dashboard', function () {
    $user = createStaffUser();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('invalid credentials receive a generic error', function () {
    $user = createStaffUser();

    $response = $this->from(route('login'))
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors(['login' => 'El correo o la contraseña no son válidos.']);
    $this->assertGuest();
});

test('an unknown email receives the same generic login error', function () {
    $response = $this->from(route('login'))
        ->post(route('login.store'), [
            'email' => 'unknown@example.com',
            'password' => 'incorrect-password',
        ]);

    $response->assertSessionHasErrors(['login' => 'El correo o la contraseña no son válidos.']);
    $this->assertGuest();
});

test('inactive accounts receive the same generic login error', function () {
    $user = createStaffUser(active: false);

    $response = $this->from(route('login'))
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertSessionHasErrors(['login' => 'El correo o la contraseña no son válidos.']);
    $this->assertGuest();
});

test('successful login regenerates the session identifier', function () {
    $user = createStaffUser();
    $this->get(route('login'));
    $originalSessionId = $this->app['session']->driver()->getId();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect($this->app['session']->driver()->getId())->not->toBe($originalSessionId);
});

test('logout invalidates the session and removes authentication', function () {
    $this->actingAs(createStaffUser())
        ->withSession(['private_marker' => 'staff-only'])
        ->post(route('logout'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('private_marker');

    $this->assertGuest();
});

test('login attempts are limited by normalized email and request origin', function () {
    $user = createStaffUser();

    foreach (range(1, 5) as $attempt) {
        $this->from(route('login'))->post(route('login.store'), [
            'email' => strtoupper($user->email),
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('login');
    }

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'incorrect-password',
    ]);

    $response->assertSessionHasErrors('login');
    expect(session('errors')->first('login'))->toContain('Espera');

    $differentOrigin = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->from(route('login'))
        ->post(route('login.store'), [
            'email' => strtoupper($user->email),
            'password' => 'incorrect-password',
        ]);

    $differentOrigin->assertSessionHasErrors(['login' => 'El correo o la contraseña no son válidos.']);
});

test('a session is revoked on the next protected request after the account is deactivated', function () {
    $user = createStaffUser();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    User::query()->whereKey($user->getKey())->update(['activo' => false]);
    auth()->forgetGuards();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('the public registration endpoints are unavailable', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

test('active authenticated staff can access customer and vehicle records', function () {
    $this->actingAs(createStaffUser());

    $this->get(route('clientes.index'))->assertOk();
    $this->get(route('vehiculos.index'))->assertOk();
});

test('role middleware allows the requested role and denies other roles', function () {
    Route::get('/__tests/administrator-only', fn () => response('authorized'))
        ->middleware(['auth', 'active', 'role:administrador']);

    $this->actingAs(createStaffUser('administrador'))
        ->get('/__tests/administrator-only')
        ->assertOk();

    auth()->forgetGuards();

    $this->actingAs(createStaffUser('mecanico'))
        ->get('/__tests/administrator-only')
        ->assertForbidden();
});

test('login never redirects to an external intended destination', function () {
    $user = createStaffUser();

    $this->withSession(['url.intended' => 'https://evil.example/collect'])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect(route('dashboard'));
});

test('the login form has no registration or unavailable password reset links', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('reservado al personal autorizado')
        ->assertDontSee('Registrar')
        ->assertDontSee('Olvidé mi contraseña')
        ->assertDontSee('Recordarme');
});

test('the internal account command stores a hashed password without printing it', function () {
    $this->seed(RoleSeeder::class);
    $password = 'SecureGarage2026!';

    $this->artisan('seibi:user:create')
        ->expectsQuestion('Nombre completo', 'Administradora SEIBI')
        ->expectsQuestion('Correo electrónico', 'admin@example.com')
        ->expectsChoice('Rol', 'administrador', ['administrador', 'mecanico', 'recepcionista'])
        ->expectsQuestion('Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas, números y símbolos)', $password)
        ->expectsQuestion('Confirma la contraseña', $password)
        ->doesntExpectOutputToContain($password)
        ->assertSuccessful();

    $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

    expect(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->role->nombre)->toBe('administrador')
        ->and($user->activo)->toBeTrue();
});

test('the internal account command rejects weak passwords in Spanish', function () {
    $this->seed(RoleSeeder::class);

    $this->artisan('seibi:user:create')
        ->expectsQuestion('Nombre completo', 'Cuenta Débil')
        ->expectsQuestion('Correo electrónico', 'weak@example.com')
        ->expectsChoice('Rol', 'mecanico', ['administrador', 'mecanico', 'recepcionista'])
        ->expectsQuestion('Contraseña (mínimo 12 caracteres, mayúsculas, minúsculas, números y símbolos)', 'weak')
        ->expectsQuestion('Confirma la contraseña', 'weak')
        ->expectsOutputToContain('La contraseña debe tener al menos 12 caracteres.')
        ->expectsOutputToContain('La contraseña debe incluir mayúsculas y minúsculas.')
        ->expectsOutputToContain('La contraseña debe incluir al menos un número.')
        ->expectsOutputToContain('La contraseña debe incluir al menos un símbolo.')
        ->doesntExpectOutputToContain('weak')
        ->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
});

function createStaffUser(string $roleName = 'administrador', bool $active = true): User
{
    $role = Role::query()->firstOrCreate(
        ['nombre' => $roleName],
        ['descripcion' => 'Rol para pruebas automatizadas.'],
    );

    return User::factory()->create([
        'id_rol' => $role->id_rol,
        'activo' => $active,
    ]);
}
