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
