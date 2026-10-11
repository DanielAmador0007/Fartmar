<?php

/*
| Pacientes (RN-10): búsqueda exacta por documento, enmascarado para el
| auditor y bitácora patient_access_logs en cada lectura autorizada.
*/

use App\Domain\Dispensing\DispenseService;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;
use Tests\Support\LogCapture;

uses(RefreshDatabase::class);

function knownPatient(): Patient
{
    return Patient::factory()->create([
        'document_type' => 'CC',
        'document_number' => '1032456789',
        'first_name' => 'Juana',
        'last_name' => 'Ficticio Pérez',
        'birth_date' => '1985-04-12',
        'phone' => '3001234567',
    ]);
}

function actingAsRole(Role $role): User
{
    $user = User::factory()->role($role)->create();
    Sanctum::actingAs($user);

    return $user;
}

it('busca por documento exacto (HMAC) aunque venga con puntos o guiones, y registra el acceso', function () {
    $patient = knownPatient();
    Patient::factory()->create(['document_type' => 'TI', 'document_number' => '1032456789']); // mismo número, otro tipo
    $user = actingAsRole(Role::AuxiliarFarmacia);

    $response = $this->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '1.032.456-789'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $patient->id)
        ->assertJsonPath('data.0.document_number', '1032456789')
        ->assertJsonPath('data.0.masked', false);

    $log = PatientAccessLog::query()->sole();
    expect($log->user_id)->toBe($user->id)
        ->and($log->patient_id)->toBe($patient->id)
        ->and($log->action)->toBe('patient.search')
        ->and($log->masked)->toBeFalse()
        ->and($log->correlation_id)->toBe($response->headers->get('X-Correlation-Id'))
        ->and($log->ip)->toBe('127.0.0.1');
});

it('una búsqueda sin resultado no crea acceso y se audita sin el número de documento', function () {
    knownPatient();
    $user = actingAsRole(Role::RegenteFarmacia);

    $this->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '1032456780'])
        ->assertOk()
        ->assertExactJson(['data' => []]);

    expect(PatientAccessLog::query()->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'patient.search_not_found')->sole();
    expect($audit->user_id)->toBe($user->id)
        ->and($audit->metadata)->toBe(['document_type' => 'CC'])
        ->and(json_encode($audit->toArray()))->not->toContain('1032456780');
});

it('valida la búsqueda: tipo de la lista y número con caracteres permitidos', function (array $payload, string $field) {
    actingAsRole(Role::AuxiliarFarmacia);

    $this->postJson('/api/v1/patients/search', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDACION')
        ->assertJsonValidationErrors($field, 'error.details.fields');
})->with([
    'sin tipo' => [['document_number' => '123456'], 'document_type'],
    'tipo inventado' => [['document_type' => 'XX', 'document_number' => '123456'], 'document_type'],
    'comodín de LIKE' => [['document_type' => 'CC', 'document_number' => '1032%'], 'document_number'],
    'demasiado corto' => [['document_type' => 'CC', 'document_number' => '12'], 'document_number'],
]);

it('médico, auxiliar y regente ven los datos completos', function (Role $role) {
    $patient = knownPatient();
    actingAsRole($role);

    $this->getJson("/api/v1/patients/{$patient->id}")
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $patient->id,
            'document_type' => 'CC',
            'document_number' => '1032456789',
            'first_name' => 'Juana',
            'last_name' => 'Ficticio Pérez',
            'birth_date' => '1985-04-12',
            'phone' => '3001234567',
            'masked' => false,
        ]]);

    expect(PatientAccessLog::query()->sole()->masked)->toBeFalse();
})->with([Role::Medico, Role::RegenteFarmacia, Role::AuxiliarFarmacia]);

it('el auditor ve los datos enmascarados y la bitácora lo registra como enmascarado', function () {
    $patient = knownPatient();
    actingAsRole(Role::Auditor);

    $response = $this->getJson("/api/v1/patients/{$patient->id}")
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $patient->id,
            'document_type' => 'CC',
            'document_number' => '******6789',
            'first_name' => 'J***',
            'last_name' => 'F*** P***',
            'birth_date' => null,
            'phone' => null,
            'masked' => true,
        ]]);

    foreach (['1032456789', 'Juana', 'Pérez', '1985', '3001234567', $patient->document_hash] as $leak) {
        expect((string) $response->getContent())->not->toContain($leak);
    }
    $log = PatientAccessLog::query()->sole();
    expect($log->action)->toBe('patient.view')->and($log->masked)->toBeTrue();
});

it('el auditor también recibe enmascarada la búsqueda', function () {
    knownPatient();
    actingAsRole(Role::Auditor);

    $this->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '1032456789'])
        ->assertOk()
        ->assertJsonPath('data.0.document_number', '******6789')
        ->assertJsonPath('data.0.first_name', 'J***')
        ->assertJsonPath('data.0.masked', true);
});

it('una lectura negada (admin) responde 403 y NO crea registro en la bitácora', function () {
    $patient = knownPatient();
    actingAsRole(Role::Admin);

    $this->getJson("/api/v1/patients/{$patient->id}")->assertForbidden()->assertJsonPath('error.code', 'NO_AUTORIZADO');
    $this->getJson("/api/v1/patients/{$patient->id}/prescriptions")->assertForbidden();
    $this->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '1032456789'])->assertForbidden();

    expect(PatientAccessLog::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'patient.%')->count())->toBe(0);
});

it('un usuario desactivado no lee pacientes', function () {
    $patient = knownPatient();
    Sanctum::actingAs(User::factory()->medico()->create(['is_active' => false]));

    $this->getJson("/api/v1/patients/{$patient->id}")->assertForbidden();
    expect(PatientAccessLog::query()->count())->toBe(0);
});

it('cada lectura crea su propio registro (append-only), incluso del mismo usuario y paciente', function () {
    $patient = knownPatient();
    actingAsRole(Role::Medico);

    $this->getJson("/api/v1/patients/{$patient->id}")->assertOk();
    $this->getJson("/api/v1/patients/{$patient->id}")->assertOk();
    $this->getJson("/api/v1/patients/{$patient->id}/prescriptions")->assertOk();

    expect(PatientAccessLog::query()->orderBy('id')->pluck('action')->all())
        ->toBe(['patient.view', 'patient.view', 'patient.prescriptions']);
});

it('las prescripciones del paciente traen lo prescrito, lo entregado, lo reservado y lo disponible', function () {
    // Controlado: una dispensación pendiente reserva 2 de 5 (S-31).
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    app(DispenseService::class)->create($s->auxiliar, $s->data(2), (string) Str::uuid());
    Sanctum::actingAs($s->auxiliar);

    $this->getJson("/api/v1/patients/{$s->patient->id}/prescriptions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $s->prescription->id)
        ->assertJsonPath('data.0.patient_id', $s->patient->id)
        ->assertJsonPath('data.0.status', 'ACTIVA')
        ->assertJsonPath('data.0.is_current', true)
        ->assertJsonPath('data.0.items.0.id', $s->item->id)
        ->assertJsonPath('data.0.items.0.product.is_controlled', true)
        ->assertJsonPath('data.0.items.0.quantity_prescribed', 5)
        ->assertJsonPath('data.0.items.0.quantity_dispensed', 0)
        ->assertJsonPath('data.0.items.0.quantity_reserved', 2)
        ->assertJsonPath('data.0.items.0.quantity_available', 3)
        ->assertJsonMissingPath('data.0.patient');

    expect(PatientAccessLog::query()->sole()->action)->toBe('patient.prescriptions');
});

it('una prescripción vencida aparece como no vigente', function () {
    $s = DispensingScenario::make();
    $s->prescription->update(['valid_until' => now()->subDays(1)->toDateString(), 'issued_at' => now()->subDays(10)]);
    Sanctum::actingAs($s->regente);

    $this->getJson("/api/v1/patients/{$s->patient->id}/prescriptions")
        ->assertOk()
        ->assertJsonPath('data.0.is_current', false);
});

it('el documento sigue cifrado en la BD aunque la API lo devuelva en claro', function () {
    $patient = knownPatient();
    actingAsRole(Role::Medico);

    $this->getJson("/api/v1/patients/{$patient->id}")->assertJsonPath('data.document_number', '1032456789');

    $raw = json_encode(DB::table('patients')->where('id', $patient->id)->first(), JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain('1032456789')->not->toContain('3001234567');
});

it('leer pacientes no deja datos personales en los logs', function () {
    $patient = knownPatient();
    $logs = LogCapture::start();
    DB::listen(fn ($query) => logger()->debug('sql', ['sql' => $query->sql, 'bindings' => $query->bindings]));
    actingAsRole(Role::Medico);

    $this->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '1032456789'])->assertOk();
    $this->getJson("/api/v1/patients/{$patient->id}")->assertOk();
    $this->getJson("/api/v1/patients/{$patient->id}/prescriptions")->assertOk();

    $output = $logs->output();
    expect($logs->records())->not->toBeEmpty();
    foreach (['1032456789', 'Juana', 'Pérez', '3001234567', $patient->document_hash] as $leak) {
        expect($output)->not->toContain($leak);
    }
});

/*
| Revisión de la Fase 4 (M2): textos libres ocultos para el auditor.
*/

it('el auditor no ve las indicaciones de la prescripción (texto libre); farmacia y médico sí', function (Role $role, ?string $expected) {
    $s = DispensingScenario::make();
    $s->item->update(['instructions' => 'Paciente embarazada: 1 cada 12 horas']);
    actingAsRole($role);

    $this->getJson("/api/v1/patients/{$s->patient->id}/prescriptions")
        ->assertOk()
        ->assertJsonPath('data.0.items.0.instructions', $expected);
})->with([
    'auditor' => [Role::Auditor, null],
    'médico' => [Role::Medico, 'Paciente embarazada: 1 cada 12 horas'],
    'auxiliar' => [Role::AuxiliarFarmacia, 'Paciente embarazada: 1 cada 12 horas'],
]);

it('el auditor no ve el motivo de rechazo de una dispensación (texto libre); el regente sí', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $service = app(DispenseService::class);
    $pending = $service->create($s->auxiliar, $s->data(2), (string) Str::uuid())->dispensation;
    $service->reject($pending, $s->regente, 'Paciente Juana sin documento');

    actingAsRole(Role::Auditor);
    $this->getJson("/api/v1/dispensations/{$pending->id}")->assertOk()->assertJsonPath('data.rejection_reason', null);

    Sanctum::actingAs($s->regente2);
    $this->getJson("/api/v1/dispensations/{$pending->id}")->assertOk()->assertJsonPath('data.rejection_reason', 'Paciente Juana sin documento');
});

/*
| Revisión de la Fase 4 (M3, B6): ver una dispensación es un acceso al
| paciente; un 403 o un 404 no se registran.
*/

it('ver una dispensación registra patient.dispensation_view (enmascarado para el auditor)', function (Role $role, bool $masked) {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    $dispensation = app(DispenseService::class)->create($s->auxiliar, $s->data(1), (string) Str::uuid())->dispensation;
    $user = actingAsRole($role);

    $correlationId = $this->getJson("/api/v1/dispensations/{$dispensation->id}")->assertOk()->headers->get('X-Correlation-Id');

    $log = PatientAccessLog::query()->sole();
    expect($log->action)->toBe('patient.dispensation_view')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->patient_id)->toBe($s->patient->id)
        ->and($log->masked)->toBe($masked)
        ->and($log->correlation_id)->toBe($correlationId);
})->with([
    'auxiliar' => [Role::AuxiliarFarmacia, false],
    'auditor' => [Role::Auditor, true],
]);

it('una dispensación negada (403) o inexistente (404) no registra acceso', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    $dispensation = app(DispenseService::class)->create($s->auxiliar, $s->data(1), (string) Str::uuid())->dispensation;

    actingAsRole(Role::Medico);
    $this->getJson("/api/v1/dispensations/{$dispensation->id}")->assertForbidden();
    actingAsRole(Role::AuxiliarFarmacia);
    $this->getJson('/api/v1/dispensations/999999')->assertNotFound();

    expect(PatientAccessLog::query()->count())->toBe(0);
});

it('B6: un paciente inexistente responde 404 y no registra acceso', function () {
    actingAsRole(Role::Medico);

    $this->getJson('/api/v1/patients/999999')->assertNotFound()->assertJsonPath('error.code', 'NO_ENCONTRADO');
    $this->getJson('/api/v1/patients/999999/prescriptions')->assertNotFound();

    expect(PatientAccessLog::query()->count())->toBe(0);
});

it('B7: la búsqueda de pacientes tiene su propio límite de 30 por minuto por usuario', function () {
    actingAsRole(Role::AuxiliarFarmacia);

    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => (string) (100000 + $i)])->assertOk();
    }
    $this->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '999999'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'DEMASIADAS_SOLICITUDES');

    // Otro usuario no se ve afectado.
    actingAsRole(Role::AuxiliarFarmacia);
    $this->postJson('/api/v1/patients/search', ['document_type' => 'CC', 'document_number' => '999999'])->assertOk();
});
