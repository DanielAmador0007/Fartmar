<?php

/*
| GET /api/v1/dispensations: listado paginado con filtros status y
| warehouse_id. Privacidad (RN-10, S-87): ningún dato del paciente (ni su
| ID), sin lotes ni motivo de rechazo, y sin escribir en patient_access_logs.
*/

use App\Domain\Dispensing\DispenseService;
use App\Models\Dispensation;
use App\Models\PatientAccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    $service = app(DispenseService::class);

    // Controlado pendiente de autorización (bodega A).
    $this->controlled = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    $this->pending = $service->create($this->controlled->auxiliar, $this->controlled->data(2), (string) Str::uuid())->dispensation;

    // Controlado rechazado por un regente (bodega A), con motivo en texto libre.
    $toReject = $service->create($this->controlled->auxiliar, $this->controlled->data(1), (string) Str::uuid())->dispensation;
    $this->rejected = $service->reject($toReject, $this->controlled->regente, 'Paciente Pérez trajo fórmula ilegible');

    // Normal completada (bodega B).
    $this->plain = DispensingScenario::make([[30, 10]], prescribed: 5);
    $this->completed = $service->create($this->plain->auxiliar, $this->plain->data(3), (string) Str::uuid())->dispensation;
});

it('lista todas las dispensaciones de la más reciente a la más antigua, paginadas', function () {
    Sanctum::actingAs($this->plain->regente);

    $this->getJson('/api/v1/dispensations')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $this->completed->id)
        ->assertJsonPath('data.1.id', $this->rejected->id)
        ->assertJsonPath('data.2.id', $this->pending->id)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 50);

    $this->getJson('/api/v1/dispensations?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.last_page', 2);
});

it('filtra por estado PENDIENTE_AUTORIZACION con la forma exacta de la fila', function () {
    Sanctum::actingAs($this->controlled->regente2);
    $s = $this->controlled;

    $row = $this->getJson('/api/v1/dispensations?status=PENDIENTE_AUTORIZACION')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->json('data.0');

    expect($row)->toBe([
        'id' => $this->pending->id,
        'status' => 'PENDIENTE_AUTORIZACION',
        'requires_authorization' => true,
        'prescription' => ['id' => $s->prescription->id, 'number' => $s->prescription->number],
        'warehouse' => ['id' => $s->warehouse->id, 'code' => $s->warehouse->code, 'name' => $s->warehouse->name],
        'items' => [[
            'id' => $this->pending->items()->sole()->id,
            'product' => [
                'id' => $s->product->id,
                'code' => $s->product->code,
                'name' => $s->product->name,
                'is_controlled' => true,
            ],
            'quantity' => 2,
        ]],
        'created_by' => ['id' => $s->auxiliar->id, 'name' => $s->auxiliar->name, 'role' => 'auxiliar_farmacia'],
        'authorized_by' => null,
        'authorized_at' => null,
        'rejected_by' => null,
        'rejected_at' => null,
        'created_at' => $this->pending->created_at->toIso8601String(),
        // regente2 no la creó: puede autorizarla o rechazarla.
        'allowed_actions' => ['authorize', 'reject'],
    ]);
});

it('RN-05: allowed_actions del listado según usuario y estado', function () {
    $s = $this->controlled;
    // Controlado pendiente creado por un regente: él no puede decidirlo.
    $byRegente = app(DispenseService::class)->create($s->regente, $s->data(1), (string) Str::uuid())->dispensation;

    $actionsFor = function (User $user) {
        Sanctum::actingAs($user);

        return collect($this->getJson('/api/v1/dispensations')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $row) => [$row['id'] => $row['allowed_actions']]);
    };

    $regente = $actionsFor($s->regente);
    expect($regente[$byRegente->id])->toBe([])               // creador regente: nada
        ->and($regente[$this->pending->id])->toBe(['authorize', 'reject'])
        ->and($regente[$this->rejected->id])->toBe([])        // RECHAZADA
        ->and($regente[$this->completed->id])->toBe([]);      // COMPLETADA

    expect($actionsFor($s->regente2)[$byRegente->id])->toBe(['authorize', 'reject']);

    foreach ([$s->auxiliar, User::factory()->auditor()->create()] as $user) {
        expect($actionsFor($user)->flatten()->all())->toBe([]);
    }
});

it('L8: el listado hace un número fijo de consultas, sin importar cuántas filas devuelva (sin N+1)', function () {
    $service = app(DispenseService::class);
    Sanctum::actingAs($this->controlled->regente2);

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/dispensations')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Por escenario: otra bodega y otros usuarios; 1 controlado pendiente del
    // auxiliar, 1 controlado del regente autorizado por regente2 (llena
    // authorized_by) y 1 más pendiente del auxiliar.
    $addRows = function () use ($service): void {
        $s = DispensingScenario::make([[30, 20]], prescribed: 10, controlled: true);
        $service->create($s->auxiliar, $s->data(1), (string) Str::uuid());
        $service->authorize($service->create($s->regente, $s->data(1), (string) Str::uuid())->dispensation, $s->regente2);
        $service->create($s->auxiliar, $s->data(2), (string) Str::uuid());
    };

    // Ambas mediciones ya tienen filas con authorizer y rejecter: si ninguna
    // fila tuviera esa FK, Eloquent omitiría la consulta y la cuenta variaría
    // por eso y no por N+1.
    $addRows();
    expect(Dispensation::query()->count())->toBe(6);
    $withSix = $countQueries();

    foreach (range(1, 4) as $i) {
        $addRows();
    }
    expect(Dispensation::query()->count())->toBe(18);
    $withEighteen = $countQueries();

    // count + página + prescription, warehouse, items, items.product,
    // creator, authorizer, rejecter = 9. allowed_actions no consulta nada.
    expect($withEighteen)->toBe($withSix)
        ->and($withEighteen)->toBeLessThanOrEqual(9);
});

it('filtra por estado y bodega combinados', function () {
    Sanctum::actingAs($this->plain->auxiliar);

    $this->getJson("/api/v1/dispensations?warehouse_id={$this->controlled->warehouse->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson("/api/v1/dispensations?warehouse_id={$this->plain->warehouse->id}&status=COMPLETADA")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->completed->id)
        ->assertJsonPath('data.0.requires_authorization', false);

    $this->getJson("/api/v1/dispensations?warehouse_id={$this->plain->warehouse->id}&status=RECHAZADA")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson('/api/v1/dispensations?status=RECHAZADA')
        ->assertOk()
        ->assertJsonPath('data.0.rejected_by.id', $this->controlled->regente->id)
        ->assertJsonPath('data.0.authorized_by', null);
});

it('valida los filtros', function (string $query) {
    Sanctum::actingAs($this->plain->auxiliar);

    $this->getJson("/api/v1/dispensations?{$query}")->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
})->with([
    'estado inexistente' => 'status=ENTREGADA',
    'bodega no numérica' => 'warehouse_id=abc',
    'per_page excesivo' => 'per_page=101',
]);

it('RN-10: el auditor ve el listado sin datos personales, sin motivo de rechazo y sin generar bitácora', function () {
    Sanctum::actingAs(User::factory()->auditor()->create());
    $patient = $this->controlled->patient;
    // Nombre distintivo: el firstName() de la factory podría coincidir con el nombre de un usuario.
    $patient->update(['first_name' => 'Zuleimy', 'last_name' => 'Ficticio Quintanilla']);

    $response = $this->getJson('/api/v1/dispensations')->assertOk()->assertJsonCount(3, 'data');
    $body = $response->getContent();

    expect($body)->not->toContain($patient->first_name)
        ->and($body)->not->toContain($patient->last_name)
        ->and($body)->not->toContain($patient->document_number)
        ->and($body)->not->toContain('Pérez')
        ->and($response->json('data.1'))->not->toHaveKeys(['rejection_reason', 'patient', 'patient_id'])
        ->and($response->json('data.0.items.0'))->not->toHaveKey('lots')
        ->and(PatientAccessLog::query()->count())->toBe(0);
});

it('S-87: el personal de farmacia tampoco genera bitácora con el listado, pero el detalle sí', function () {
    Sanctum::actingAs($this->plain->auxiliar);

    $this->getJson('/api/v1/dispensations')->assertOk();
    expect(PatientAccessLog::query()->count())->toBe(0);

    $this->getJson("/api/v1/dispensations/{$this->completed->id}")->assertOk();
    expect(PatientAccessLog::query()->count())->toBe(1);
});

it('el médico y el admin no pueden listar dispensaciones', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->getJson('/api/v1/dispensations')->assertForbidden()->assertJsonPath('error.code', 'NO_AUTORIZADO');
    expect(Dispensation::query()->count())->toBe(3);
})->with(['medico', 'admin']);
