<?php

/*
| Auditoría de login fallido (S-71): audit_logs con HMAC del correo, sin el
| correo en claro, para detectar fuerza bruta.
*/

use App\Domain\Auth\LoginService;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('un login fallido queda en audit_logs con HMAC del correo, motivo, ip y correlation_id, sin el correo en claro', function (string $case) {
    $user = User::factory()->create(['email' => 'objetivo@fartmar.test', 'is_active' => $case !== 'inactivo']);
    [$email, $password, $reason] = match ($case) {
        'clave incorrecta' => ['Objetivo@Fartmar.test ', 'otra', 'CLAVE_INCORRECTA'],
        'usuario inexistente' => ['nadie@fartmar.test', 'password', 'USUARIO_DESCONOCIDO'],
        'inactivo' => ['objetivo@fartmar.test', 'password', 'USUARIO_INACTIVO'],
    };

    $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password], ['X-Correlation-Id' => 'login-malo-1'])
        ->assertUnauthorized();

    $audit = AuditLog::query()->where('action', 'auth.login_failed')->sole();
    expect($audit->user_id)->toBeNull()
        ->and($audit->metadata)->toEqual(['email_hash' => LoginService::emailHash($email), 'reason' => $reason])
        ->and($audit->metadata['email_hash'])->toHaveLength(64)
        ->and($audit->ip)->toBe('127.0.0.1')
        ->and($audit->correlation_id)->toBe('login-malo-1')
        ->and($audit->auditable_id)->toBe($case === 'usuario inexistente' ? null : $user->id);

    $row = json_encode(AuditLog::query()->toBase()->where('id', $audit->id)->first(), JSON_THROW_ON_ERROR);
    expect(strtolower($row))->not->toContain('objetivo@')->not->toContain('nadie@')
        // No es un SHA-256 sin clave (que se revierte probando correos conocidos).
        ->and($audit->metadata['email_hash'])->not->toBe(hash('sha256', mb_strtolower(trim($email))));
})->with(['clave incorrecta', 'usuario inexistente', 'inactivo']);

it('el hash del correo agrupa los intentos contra la misma cuenta sin importar mayúsculas', function () {
    User::factory()->create(['email' => 'repetido@fartmar.test']);

    foreach (['repetido@fartmar.test', 'REPETIDO@fartmar.test', ' repetido@FARTMAR.test'] as $email) {
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'mala'])->assertUnauthorized();
    }

    expect(AuditLog::query()->where('action', 'auth.login_failed')->get()->pluck('metadata.email_hash')->unique())->toHaveCount(1);
});

it('un login exitoso no registra auth.login_failed', function () {
    User::factory()->create(['email' => 'bien@fartmar.test']);
    $this->postJson('/api/v1/auth/login', ['email' => 'bien@fartmar.test', 'password' => 'password'])->assertOk();

    expect(AuditLog::query()->where('action', 'auth.login_failed')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'auth.login')->exists())->toBeTrue();
});
