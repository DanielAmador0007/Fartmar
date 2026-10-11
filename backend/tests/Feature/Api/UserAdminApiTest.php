<?php

/*
| Administración de usuarios (solo admin): creación, cambios de rol/estado,
| revocación de tokens y auditoría sin contraseñas.
*/

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function adminTestLogin(string $email): string
{
    return (string) test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password'])
        ->assertOk()
        ->json('token');
}

// --- CRUD de usuarios ----------------------------------------------------------------

it('el admin lista usuarios sin contraseñas ni tokens', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(3)->create();
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'is_active']], 'meta' => ['total']]);

    expect((string) $response->getContent())->not->toContain('password')->not->toContain('$2y$')->not->toContain('remember_token');
});

it('el admin crea un usuario con clave hasheada y queda auditado sin la clave', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/users', [
        'name' => 'Nueva Auxiliar', 'email' => ' Nueva.Aux@Fartmar.test', 'password' => 'ClaveSegura2026', 'role' => 'auxiliar_farmacia',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'nueva.aux@fartmar.test')
        ->assertJsonPath('data.role', 'auxiliar_farmacia')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonMissingPath('data.password');

    $user = User::query()->findOrFail($response->json('data.id'));
    expect(Hash::check('ClaveSegura2026', $user->password))->toBeTrue();

    $audit = AuditLog::query()->where('action', 'user.created')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->auditable_id)->toBe($user->id)
        ->and($audit->metadata)->toEqual(['role' => 'auxiliar_farmacia'])
        ->and(json_encode($audit->metadata))->not->toContain('ClaveSegura2026');

    // Puede iniciar sesión con la clave asignada.
    $this->postJson('/api/v1/auth/login', ['email' => 'nueva.aux@fartmar.test', 'password' => 'ClaveSegura2026'])->assertOk();
});

it('valida la creación: correo único, clave robusta y rol de la lista', function (array $overrides, string $field) {
    User::factory()->create(['email' => 'existente@fartmar.test']);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/v1/users', array_replace([
        'name' => 'Alguien', 'email' => 'alguien@fartmar.test', 'password' => 'ClaveSegura2026', 'role' => 'medico',
    ], $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field, 'error.details.fields');
})->with([
    'correo repetido (otra capitalización)' => [['email' => 'EXISTENTE@fartmar.test'], 'email'],
    'clave corta' => [['password' => 'corta1'], 'password'],
    'clave sin números' => [['password' => 'soloLetrasLargas'], 'password'],
    'rol inventado' => [['role' => 'superusuario'], 'role'],
]);

it('desactivar a un usuario revoca todos sus tokens y queda auditado', function () {
    $admin = User::factory()->admin()->create();
    $aux = User::factory()->auxiliar()->create(['email' => 'aux.tokens@fartmar.test']);
    $token1 = adminTestLogin('aux.tokens@fartmar.test');
    adminTestLogin('aux.tokens@fartmar.test');
    expect($aux->tokens()->count())->toBe(2);

    Sanctum::actingAs($admin);
    $this->patchJson("/api/v1/users/{$aux->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($aux->tokens()->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'user.updated')->sole();
    expect($audit->metadata)->toEqual([
        'changed' => ['is_active'], 'tokens_revoked' => 2, 'is_active' => ['from' => true, 'to' => false],
    ]);

    // El token anterior ya no sirve.
    app('auth')->forgetGuards();
    $this->withToken($token1)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('cambiar el rol revoca los tokens (los permisos de la sesión cambiaron)', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->auxiliar()->create(['email' => 'cambia.rol@fartmar.test']);
    adminTestLogin('cambia.rol@fartmar.test');
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$user->id}", ['role' => 'regente_farmacia'])
        ->assertOk()
        ->assertJsonPath('data.role', 'regente_farmacia');

    expect($user->tokens()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'user.updated')->sole()->metadata)->toEqual([
            'changed' => ['role'], 'tokens_revoked' => 1, 'role' => ['from' => 'auxiliar_farmacia', 'to' => 'regente_farmacia'],
        ]);
});

it('cambiar solo el nombre no revoca tokens y no audita el nombre', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->medico()->create(['email' => 'nombre@fartmar.test', 'name' => 'Nombre Viejo']);
    adminTestLogin('nombre@fartmar.test');
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$user->id}", ['name' => 'Nombre Nuevo'])->assertOk()->assertJsonPath('data.name', 'Nombre Nuevo');

    expect($user->tokens()->count())->toBe(1);
    $metadata = AuditLog::query()->where('action', 'user.updated')->sole()->metadata;
    expect($metadata)->toEqual(['changed' => ['name'], 'tokens_revoked' => 0])
        ->and(json_encode($metadata))->not->toContain('Nombre');
});

it('reactivar un usuario no revoca nada y un PATCH sin cambios no audita', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->auxiliar()->create(['is_active' => false]);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$user->id}", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    $this->patchJson("/api/v1/users/{$user->id}", ['is_active' => true])->assertOk();

    expect(AuditLog::query()->where('action', 'user.updated')->count())->toBe(1);
});

it('un admin no puede desactivarse ni quitarse el rol admin a sí mismo', function (array $payload) {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$admin->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'OPERACION_NO_PERMITIDA')
        ->assertJsonPath('error.details.reason', 'AUTOBLOQUEO');

    expect($admin->fresh()?->is_active)->toBeTrue()
        ->and($admin->fresh()?->role)->toBe(Role::Admin)
        ->and(AuditLog::query()->where('action', 'user.updated')->exists())->toBeFalse();
})->with([
    'desactivarse' => [['is_active' => false]],
    'quitarse admin' => [['role' => 'auditor']],
]);

it('un admin sí puede cambiarse el nombre y desactivar a otro admin', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$admin->id}", ['name' => 'Admin Renombrado'])->assertOk();
    $this->patchJson("/api/v1/users/{$other->id}", ['is_active' => false])->assertOk();
});

it('PATCH valida: al menos un campo, rol de la lista y no permite cambiar correo ni clave', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email' => 'fijo@fartmar.test']);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$user->id}", [])->assertUnprocessable();
    $this->patchJson("/api/v1/users/{$user->id}", ['role' => 'root'])->assertUnprocessable();
    $this->patchJson("/api/v1/users/{$user->id}", ['email' => 'otro@fartmar.test', 'password' => 'NuevaClave2026', 'name' => 'Ok Nombre'])->assertOk();

    expect($user->fresh()?->email)->toBe('fijo@fartmar.test')
        ->and(Hash::check('password', (string) $user->fresh()?->password))->toBeTrue();
});

it('no existe DELETE de usuarios', function () {
    $user = User::factory()->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->deleteJson("/api/v1/users/{$user->id}")->assertStatus(405);
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
