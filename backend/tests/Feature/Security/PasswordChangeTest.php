<?php

/*
| S-77 (revisión de la Fase 4, A2): contraseña temporal asignada por el
| admin. Con must_change_password solo se permite cambiarla, ver /auth/me y
| cerrar sesión; el cambio exige la actual, revoca las otras sesiones y se
| audita.
*/

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** El admin crea al usuario por la API (así nace con clave temporal) y este inicia sesión. */
function temporaryUserToken(string $email = 'temporal@fartmar.test', string $role = 'medico'): string
{
    Sanctum::actingAs(User::factory()->admin()->create());
    test()->postJson('/api/v1/users', [
        'name' => 'Usuario Temporal', 'email' => $email, 'password' => 'TemporalAdmin2026', 'role' => $role,
    ])->assertCreated();
    app('auth')->forgetGuards();

    return (string) test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'TemporalAdmin2026'])
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true)
        ->json('token');
}

it('con contraseña temporal solo permite auth/me, auth/password y auth/logout; lo demás es 403 CAMBIO_CLAVE_REQUERIDO', function () {
    $token = temporaryUserToken(role: 'regente_farmacia');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);

    foreach (['/api/v1/stocks', '/api/v1/kardex', '/api/v1/transfers'] as $uri) {
        $this->withToken($token)->getJson($uri)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'CAMBIO_CLAVE_REQUERIDO');
    }
    $this->withToken($token)->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '123456'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CAMBIO_CLAVE_REQUERIDO');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('cambiar la contraseña quita la marca, revoca las otras sesiones, mantiene la actual y se audita sin la clave', function () {
    $token = temporaryUserToken();
    $user = User::query()->where('email', 'temporal@fartmar.test')->sole();
    // Segunda sesión abierta con la clave temporal (p. ej. la del admin que la conoce).
    $this->postJson('/api/v1/auth/login', ['email' => 'temporal@fartmar.test', 'password' => 'TemporalAdmin2026'])->assertOk();
    expect($user->tokens()->count())->toBe(2);

    $this->withToken($token)->postJson('/api/v1/auth/password', [
        'current_password' => 'TemporalAdmin2026',
        'password' => 'MiClavePropia2026',
        'password_confirmation' => 'MiClavePropia2026',
    ])
        ->assertOk()
        ->assertJsonPath('data.must_change_password', false);

    $fresh = $user->fresh();
    expect($fresh?->must_change_password)->toBeFalse()
        ->and(Hash::check('MiClavePropia2026', (string) $fresh?->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);

    // La sesión con la que cambió sigue sirviendo y ya puede operar.
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '123456'])
        ->assertOk();

    $audit = AuditLog::query()->where('action', 'user.password_changed')->sole();
    expect($audit->user_id)->toBe($user->id)
        ->and($audit->metadata)->toEqual(['forced' => true, 'tokens_revoked' => 1])
        ->and(json_encode($audit->toArray()))->not->toContain('MiClavePropia2026');
});

it('valida el cambio: clave actual correcta, confirmación, distinta de la actual, robusta y de hasta 72 bytes', function (array $payload, string $field) {
    $user = User::factory()->create(); // contraseña "password"
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/password', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field, 'error.details.fields');

    expect(Hash::check('password', (string) $user->fresh()?->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.password_changed')->exists())->toBeFalse();
})->with([
    'actual incorrecta' => [['current_password' => 'otra', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'], 'current_password'],
    'sin confirmar' => [['current_password' => 'password', 'password' => 'NuevaClave2026', 'password_confirmation' => 'OtraCosa2026'], 'password'],
    'corta' => [['current_password' => 'password', 'password' => 'corta1', 'password_confirmation' => 'corta1'], 'password'],
    'más de 72 bytes' => [['current_password' => 'password', 'password' => str_repeat('ñ', 40).'a1', 'password_confirmation' => str_repeat('ñ', 40).'a1'], 'password'],
]);

it('la nueva contraseña debe ser distinta de la actual', function () {
    $user = User::factory()->create(['password' => 'MismaClave2026']);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/password', [
        'current_password' => 'MismaClave2026', 'password' => 'MismaClave2026', 'password_confirmation' => 'MismaClave2026',
    ])->assertUnprocessable()->assertJsonValidationErrors('password', 'error.details.fields');
});

it('cambiar la contraseña tiene su propio límite: 5 por minuto por usuario', function () {
    Sanctum::actingAs(User::factory()->create());

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/password', ['current_password' => 'mala', 'password' => 'x', 'password_confirmation' => 'x'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/password', ['current_password' => 'mala'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'DEMASIADAS_SOLICITUDES');
});

it('los usuarios demo del seeder entran sin contraseña temporal', function () {
    $this->seed(UserSeeder::class);

    expect(User::query()->where('must_change_password', true)->exists())->toBeFalse();
    $this->postJson('/api/v1/auth/login', ['email' => 'medico@fartmar.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.must_change_password', false);
});
