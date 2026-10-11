<?php

/*
| Logs sin datos personales (RN-10) y X-Correlation-Id (Fase 4).
| Los logs se capturan con LogCapture: canal "json" real con su redacción y
| su formateador, solo que en memoria en vez de stderr.
*/

use App\Logging\RedactSensitiveData;
use App\Models\AuditLog;
use App\Models\Dispensation;
use App\Models\KardexMovement;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;
use Tests\Support\LogCapture;

uses(RefreshDatabase::class);

// --- X-Correlation-Id ---------------------------------------------------------

it('genera un X-Correlation-Id (UUID) si el cliente no lo envía, también en un 401', function () {
    $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect(Str::isUuid((string) $response->headers->get('X-Correlation-Id')))->toBeTrue();
});

it('respeta un X-Correlation-Id válido y reemplaza uno inválido', function (string $incoming, bool $kept) {
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $returned = (string) $this->getJson('/api/v1/auth/me', ['X-Correlation-Id' => $incoming])
        ->assertOk()
        ->headers->get('X-Correlation-Id');

    if ($kept) {
        expect($returned)->toBe($incoming);
    } else {
        expect($returned)->not->toBe($incoming)->and(Str::isUuid($returned))->toBeTrue();
    }
})->with([
    'uuid' => ['3f1c2a9e-6b1d-4e7a-9c55-0a8b7d6e5f43', true],
    'alfanumérico con guiones' => ['frontend-req-0001', true],
    'con espacios' => ['hola mundo', false],
    'con salto de línea codificado' => ['abc%0Ainyectado', false],
    'más de 64 caracteres' => [str_repeat('a', 65), false],
    'con caracteres de control JSON' => ['{"x":1}', false],
]);

it('la dispensación guarda el correlation_id en dispensations y kardex, y correlation_id + ip en audit_logs', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);
    $correlationId = (string) Str::uuid();

    $this->postJson('/api/v1/dispensations', $s->payload(2), [
        'Idempotency-Key' => (string) Str::uuid(),
        'X-Correlation-Id' => $correlationId,
    ])->assertCreated()->assertHeader('X-Correlation-Id', $correlationId);

    expect(Dispensation::query()->sole()->correlation_id)->toBe($correlationId)
        ->and(KardexMovement::query()->pluck('correlation_id')->unique()->all())->toBe([$correlationId]);

    $audit = AuditLog::query()->where('action', 'dispensation.created')->sole();
    expect($audit->correlation_id)->toBe($correlationId)
        ->and($audit->ip)->toBe('127.0.0.1');
});

// --- Redacción ----------------------------------------------------------------

it('un 500 por QueryException no deja en el log el correo, el hash de la clave ni la línea DETAIL', function () {
    config(['app.debug' => false]);
    $email = 'duplicado.secreto@fartmar.test';
    User::factory()->create(['email' => $email]);
    Route::middleware('api')->post('/api/v1/_prueba/duplicado', fn () => User::query()->create([
        'name' => 'Nombre Secreto', 'email' => $email, 'password' => 'ClaveSecreta123',
    ]));
    $logs = LogCapture::start();

    $response = $this->postJson('/api/v1/_prueba/duplicado', [], ['X-Correlation-Id' => 'prueba-500'])
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'ERROR_INTERNO');

    $output = $logs->output();
    // Se registró el error, en JSON, con lo útil para depurar...
    expect($logs->records())->not->toBeEmpty()
        ->and(json_decode(strtok($output, "\n") ?: '', true))->toBeArray()
        ->and($output)->toContain('users_email_unique')
        ->and($output)->toContain('insert into')
        ->and($output)->toContain('prueba-500');
    // ...y sin datos personales ni secretos.
    foreach ([$email, 'Nombre Secreto', 'ClaveSecreta123', '$2y$', 'DETAIL', 'Key (email)', 'Host: ', 'fartmar_test'] as $leak) {
        expect($output)->not->toContain($leak);
    }
    expect((string) $response->getContent())->not->toContain($email);
});

it('redacta una QueryException con los valores en el mensaje aunque no se hayan enmascarado los bindings', function () {
    $pdo = new PDOException(
        "SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"patients\" violates check constraint \"x\"\n"
        .'DETAIL:  Failing row contains (1, CC, 1032456789, Juana, Pérez, 3001234567).',
    );
    $e = new QueryException(
        'pgsql',
        'insert into patients (document_number, first_name, phone) values (?, ?, ?)',
        ['1032456789', 'Juana', '3001234567'],
        $pdo,
        ['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'database' => 'fartmar'],
    );
    expect($e->getMessage())->toContain('1032456789'); // así lo arma Laravel sin máscara

    $logs = LogCapture::start();
    Log::error($e->getMessage(), ['exception' => $e]);

    $output = $logs->output();
    expect($output)->toContain('insert into patients (document_number, first_name, phone) values (?, ?, ?)')
        ->and($output)->toContain('Check violation');
    foreach (['1032456789', 'Juana', 'Pérez', '3001234567', 'Failing row', 'Host: db'] as $leak) {
        expect($output)->not->toContain($leak);
    }
});

it('redacta claves sensibles en cualquier nivel, modelos completos, correos y números largos', function () {
    $patient = Patient::factory()->create(['first_name' => 'Rosalba', 'document_number' => '1098765432']);
    $logs = LogCapture::start();

    Log::warning('Consulta de 1098765432 por soporte@fartmar.test', [
        'password' => 'p4ss',
        'Authorization' => 'Bearer 1|abc',
        'headers' => ['cookie' => 'laravel_session=xyz', 'x-correlation-id' => 'ok-123'],
        'patient' => $patient,
        'data' => ['first_name' => 'Rosalba', 'document_number' => '1098765432', 'phone' => '3000000000', 'quantity' => 3],
        'note' => 'escribir a rosalba@correo.test',
        'dispensation_id' => 42,
    ]);

    $record = $logs->records()[0];
    expect($record->message)->toBe('Consulta de [NUM] por [EMAIL]')
        ->and($record->context['password'])->toBe(RedactSensitiveData::REDACTED)
        ->and($record->context['Authorization'])->toBe(RedactSensitiveData::REDACTED)
        ->and($record->context['headers'])->toBe(['cookie' => RedactSensitiveData::REDACTED, 'x-correlation-id' => 'ok-123'])
        ->and($record->context['patient'])->toBe(['model' => Patient::class, 'id' => $patient->id])
        ->and($record->context['data'])->toBe([
            'first_name' => RedactSensitiveData::REDACTED,
            'document_number' => RedactSensitiveData::REDACTED,
            'phone' => RedactSensitiveData::REDACTED,
            'quantity' => 3,
        ])
        ->and($record->context['note'])->toBe('escribir a [EMAIL]')
        ->and($record->context['dispensation_id'])->toBe(42);

    expect($logs->output())->not->toContain('Rosalba')->not->toContain('1098765432');
});

it('una dispensación no deja el nombre ni el documento del paciente en los logs (ni en las consultas SQL registradas)', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    $s->patient->update(['first_name' => 'Hermenegilda', 'last_name' => 'Ficticio Zuluaga', 'document_number' => '5550001112']);
    $logs = LogCapture::start();
    // Peor caso: alguien activa el registro de cada consulta con sus valores.
    DB::listen(fn ($query) => Log::debug('sql', ['sql' => $query->sql, 'bindings' => $query->bindings]));
    Sanctum::actingAs($s->auxiliar);

    $this->postJson('/api/v1/dispensations', $s->payload(2), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $this->getJson("/api/v1/patients/{$s->patient->id}")->assertOk();

    $output = $logs->output();
    expect($logs->records())->not->toBeEmpty();
    foreach (['Hermenegilda', 'Zuluaga', '5550001112', $s->patient->document_hash] as $leak) {
        expect($output)->not->toContain($leak);
    }
});

// --- Hallazgos de la prueba manual en el contenedor (Fase 4) ---------------------

it('sin token y sin "Accept: application/json" responde 401 uniforme, no 500', function () {
    $this->get('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'NO_AUTENTICADO')
        ->assertHeader('X-Correlation-Id');
});

it('un error de negocio esperado (credenciales inválidas, stock insuficiente) no se escribe en el log', function () {
    $s = DispensingScenario::make([[30, 1]], prescribed: 5);
    $logs = LogCapture::start();

    $this->postJson('/api/v1/auth/login', ['email' => 'nadie@fartmar.test', 'password' => 'x'])->assertUnauthorized();
    Sanctum::actingAs($s->auxiliar);
    $this->postJson('/api/v1/dispensations', $s->payload(3), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'STOCK_INSUFICIENTE');

    expect($logs->records())->toBe([]);
});
