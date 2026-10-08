<?php

/*
| Usuarios, pacientes y bitácoras (RN-10).
*/

use App\Domain\Patients\DocumentHasher;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rechaza roles fuera de los 5 definidos', function () {
    $user = User::factory()->create();

    expectDbRejects(
        fn () => DB::update("UPDATE users SET role = 'superusuario' WHERE id = ?", [$user->id]),
        'users_role_check',
    );
});

it('guarda el documento cifrado y un HMAC para buscarlo', function () {
    $patient = Patient::factory()->create(['document_type' => 'CC', 'document_number' => '9912345678']);

    $raw = DB::table('patients')->where('id', $patient->id)->first();

    expect($raw->document_number)->not->toContain('9912345678')
        ->and($raw->document_hash)->toBe(DocumentHasher::hash('CC', '9912345678'))
        ->and($raw->document_hash)->not->toBe(hash('sha256', 'CC:9912345678'))
        ->and(Patient::query()->where('document_hash', DocumentHasher::hash('cc', ' 99.123.456-78 '))->first()?->id)
        ->toBe($patient->id)
        ->and($patient->fresh()?->document_number)->toBe('9912345678');
});

it('rechaza dos pacientes con el mismo documento', function () {
    Patient::factory()->create(['document_type' => 'CC', 'document_number' => '9911111111']);

    expectDbRejects(
        fn () => Patient::factory()->create(['document_type' => 'CC', 'document_number' => '9911111111']),
        'patients_document_hash_unique',
    );
});

it('RN-10: las bitácoras de acceso y auditoría son de solo inserción', function () {
    $user = User::factory()->create();
    $access = PatientAccessLog::query()->create([
        'user_id' => $user->id, 'patient_id' => Patient::factory()->create()->id, 'action' => 'VER',
    ]);
    $audit = AuditLog::query()->create([
        'user_id' => $user->id, 'action' => 'TRASLADO_APROBADO', 'metadata' => ['transfer_id' => 1],
    ]);

    expectDbRejects(fn () => DB::update('UPDATE patient_access_logs SET action = ? WHERE id = ?', ['X', $access->id]), 'solo inserción');
    expectDbRejects(fn () => DB::delete('DELETE FROM patient_access_logs WHERE id = ?', [$access->id]), 'solo inserción');
    expectDbRejects(fn () => DB::update('UPDATE audit_logs SET action = ? WHERE id = ?', ['X', $audit->id]), 'solo inserción');
    expectDbRejects(fn () => DB::delete('DELETE FROM audit_logs WHERE id = ?', [$audit->id]), 'solo inserción');
});

/*
| Huecos agregados en la revisión de QA (Fase 1, cierre).
*/

it('RN-10: documento y teléfono se guardan cifrados; la búsqueda exacta va por document_hash', function () {
    $patient = Patient::factory()->create([
        'document_type' => 'TI', 'document_number' => '9988776655', 'phone' => '3001112233',
    ]);
    Patient::factory()->create(['document_type' => 'CC', 'document_number' => '9988776655']); // mismo número, otro tipo

    $raw = (array) DB::table('patients')->where('id', $patient->id)->first();
    $rawRow = json_encode($raw, JSON_THROW_ON_ERROR);

    // Ninguna columna cruda contiene el documento ni el teléfono en claro.
    expect($rawRow)->not->toContain('9988776655')
        ->and($rawRow)->not->toContain('3001112233')
        ->and($raw['document_number'])->not->toBe($patient->document_number)
        ->and(DB::table('patients')->where('document_number', '9988776655')->exists())->toBeFalse()
        // El hash distingue tipo de documento y no coincide con un SHA-256 sin clave.
        ->and(Patient::query()->where('document_hash', DocumentHasher::hash('TI', '9988776655'))->pluck('id')->all())
        ->toBe([$patient->id])
        ->and(Patient::query()->where('document_hash', DocumentHasher::hash('TI', '9988776656'))->exists())->toBeFalse()
        ->and($patient->fresh()?->phone)->toBe('3001112233');
});

it('cambiar el documento recalcula document_hash', function () {
    $patient = Patient::factory()->create(['document_type' => 'CC', 'document_number' => '9900000100']);

    $patient->update(['document_number' => '9900000200']);

    expect(DB::table('patients')->where('id', $patient->id)->value('document_hash'))
        ->toBe(DocumentHasher::hash('CC', '9900000200'));
});

it('rechaza tipos de documento fuera de la lista', function () {
    expectDbRejects(
        fn () => Patient::factory()->create(['document_type' => 'XX']),
        'patients_document_type_check',
    );
});

it('RN-10: las bitácoras rechazan acciones vacías y también son inmutables vía Eloquent', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create();

    expectDbRejects(fn () => DB::table('patient_access_logs')->insert([
        'user_id' => $user->id, 'patient_id' => $patient->id, 'action' => '  ',
    ]), 'patient_access_logs_action_not_blank');
    expectDbRejects(fn () => DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => '']), 'audit_logs_action_not_blank');

    $access = PatientAccessLog::query()->create(['user_id' => $user->id, 'patient_id' => $patient->id, 'action' => 'VER']);
    $audit = AuditLog::query()->create(['user_id' => $user->id, 'action' => 'X', 'metadata' => []]);

    expectDbRejects(fn () => $access->update(['action' => 'BORRADO']), 'solo inserción');
    expectDbRejects(fn () => $access->delete(), 'solo inserción');
    expectDbRejects(fn () => $audit->update(['action' => 'BORRADO']), 'solo inserción');
    expectDbRejects(fn () => $audit->delete(), 'solo inserción');

    expect(PatientAccessLog::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe(1);
});

it('no se puede borrar un paciente con accesos registrados en la bitácora', function () {
    $patient = Patient::factory()->create();
    PatientAccessLog::query()->create([
        'user_id' => User::factory()->create()->id, 'patient_id' => $patient->id, 'action' => 'VER',
    ]);

    expectDbRejects(fn () => DB::table('patients')->where('id', $patient->id)->delete(), 'patient_access_logs_patient_id_foreign');
});
