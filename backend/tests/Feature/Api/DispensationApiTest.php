<?php

/*
| API de dispensación: códigos HTTP, idempotencia por header, formato de
| errores y permisos (Policies). La lógica fina está en DispenseServiceTest.
*/

use App\Models\Dispensation;
use App\Models\KardexMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $payload
 */
function postDispensation(array $payload, ?string $key = null): TestResponse
{
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    return test()->postJson('/api/v1/dispensations', $payload, $headers);
}

it('POST crea la dispensación (201) con lotes FEFO y sin datos personales del paciente', function () {
    $s = DispensingScenario::make([[90, 10], [15, 2]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);

    $response = postDispensation($s->payload(4), (string) Str::uuid())
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('data.status', 'COMPLETADA')
        ->assertJsonPath('data.patient_id', $s->patient->id)
        ->assertJsonPath('data.items.0.quantity', 4)
        ->assertJsonPath('data.items.0.lots.0.lot_id', $s->lots[1]->id)
        ->assertJsonPath('data.items.0.lots.0.quantity', 2)
        ->assertJsonPath('data.items.0.lots.1.lot_id', $s->lots[0]->id)
        ->assertJsonPath('data.items.0.lots.1.quantity', 2)
        ->assertJsonPath('data.created_by.id', $s->auxiliar->id);

    $body = (string) $response->getContent();
    expect($body)->not->toContain($s->patient->first_name)
        ->and($body)->not->toContain($s->patient->last_name)
        ->and($body)->not->toContain($s->patient->document_number)
        ->and($s->totalStock())->toBe(8);
});

it('RN-09: reintento con la misma clave devuelve 200 + Idempotent-Replayed sin nueva salida', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);
    $key = (string) Str::uuid();

    $first = postDispensation($s->payload(2), $key)->assertCreated();
    $second = postDispensation($s->payload(2), $key)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data'))->toEqual($first->json('data'))
        ->and($s->totalStock())->toBe(8)
        ->and(KardexMovement::query()->count())->toBe(1)
        ->and(Dispensation::query()->count())->toBe(1);
});

it('RN-09: misma clave con otro contenido responde 409 IDEMPOTENCIA_CONFLICTO', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);
    $key = (string) Str::uuid();

    postDispensation($s->payload(2), $key)->assertCreated();
    postDispensation($s->payload(3), $key)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCIA_CONFLICTO');

    expect($s->totalStock())->toBe(8);
});

it('RN-09: sin header Idempotency-Key responde 422 y no dispensa', function () {
    $s = DispensingScenario::make();
    Sanctum::actingAs($s->auxiliar);

    postDispensation($s->payload(1))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['idempotency_key']]]]);

    expect(Dispensation::query()->count())->toBe(0);
});

it('valida el cuerpo: líneas vacías, repetidas o con cantidad cero', function () {
    $s = DispensingScenario::make();
    Sanctum::actingAs($s->auxiliar);

    $payload = $s->payload(0);
    $payload['items'][] = ['prescription_item_id' => $s->item->id, 'quantity' => 1];

    postDispensation($payload, (string) Str::uuid())
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['items.0.quantity', 'items.0.prescription_item_id']]]]);
});

it('RN-03: stock insuficiente responde 409 STOCK_INSUFICIENTE con disponible vs solicitado', function () {
    $s = DispensingScenario::make([[30, 2], [-5, 50]], prescribed: 10);
    Sanctum::actingAs($s->auxiliar);

    postDispensation($s->payload(3), (string) Str::uuid())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'STOCK_INSUFICIENTE')
        ->assertJsonPath('error.details.requested', 3)
        ->assertJsonPath('error.details.available', 2);

    expect($s->totalStock())->toBe(52);
});

it('RN-04: exceder lo prescrito responde 422 PRESCRIPCION_EXCEDIDA', function () {
    $s = DispensingScenario::make([[30, 50]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);

    postDispensation($s->payload(6), (string) Str::uuid())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PRESCRIPCION_EXCEDIDA')
        ->assertJsonPath('error.details.remaining', 5);
});

it('RN-04: prescripción vencida responde 422 PRESCRIPCION_NO_VIGENTE', function () {
    $s = DispensingScenario::make();
    $s->prescription->update(['issued_at' => now()->subDays(60), 'valid_until' => now('America/Bogota')->subDays(2)->toDateString()]);
    Sanctum::actingAs($s->auxiliar);

    postDispensation($s->payload(1), (string) Str::uuid())
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PRESCRIPCION_NO_VIGENTE')
        ->assertJsonPath('error.details.reason', 'VENCIDA');
});

it('RN-05: controlado se crea PENDIENTE_AUTORIZACION y un regente distinto lo autoriza', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);

    Sanctum::actingAs($s->auxiliar);
    $id = postDispensation($s->payload(2), (string) Str::uuid())
        ->assertCreated()
        ->assertJsonPath('data.status', 'PENDIENTE_AUTORIZACION')
        ->assertJsonPath('data.requires_authorization', true)
        ->assertJsonPath('data.items.0.lots', [])
        ->json('data.id');
    expect($s->totalStock())->toBe(10);

    Sanctum::actingAs($s->regente);
    $this->postJson("/api/v1/dispensations/{$id}/authorize")
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETADA')
        ->assertJsonPath('data.authorized_by.id', $s->regente->id)
        ->assertJsonPath('data.items.0.lots.0.quantity', 2);

    expect($s->totalStock())->toBe(8);
});

it('RN-05: el regente que creó la dispensación recibe 403 SEGREGACION_FUNCIONES', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    Sanctum::actingAs($s->regente);

    $id = postDispensation($s->payload(2), (string) Str::uuid())->assertCreated()->json('data.id');

    $this->postJson("/api/v1/dispensations/{$id}/authorize")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'SEGREGACION_FUNCIONES');

    expect($s->totalStock())->toBe(10);
});

it('RN-05: un auxiliar no puede autorizar ni rechazar (403 por Policy)', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    Sanctum::actingAs($s->regente);
    $id = postDispensation($s->payload(2), (string) Str::uuid())->json('data.id');

    Sanctum::actingAs($s->auxiliar);
    $this->postJson("/api/v1/dispensations/{$id}/authorize")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'NO_AUTORIZADO');
    $this->postJson("/api/v1/dispensations/{$id}/reject", ['reason' => 'No corresponde'])
        ->assertForbidden();
});

it('RN-05: un regente inactivo no puede autorizar', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    Sanctum::actingAs($s->auxiliar);
    $id = postDispensation($s->payload(2), (string) Str::uuid())->json('data.id');

    $s->regente2->update(['is_active' => false]);
    Sanctum::actingAs($s->regente2);
    $this->postJson("/api/v1/dispensations/{$id}/authorize")->assertForbidden();
});

it('RN-05: rechazo exige motivo, deja constancia y luego no se puede autorizar (409)', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    Sanctum::actingAs($s->auxiliar);
    $id = postDispensation($s->payload(2), (string) Str::uuid())->json('data.id');

    Sanctum::actingAs($s->regente);
    $this->postJson("/api/v1/dispensations/{$id}/reject", [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION');

    $this->postJson("/api/v1/dispensations/{$id}/reject", ['reason' => 'Fórmula con enmendaduras'])
        ->assertOk()
        ->assertJsonPath('data.status', 'RECHAZADA')
        ->assertJsonPath('data.rejection_reason', 'Fórmula con enmendaduras')
        ->assertJsonPath('data.rejected_by.id', $s->regente->id);

    Sanctum::actingAs($s->regente2);
    $this->postJson("/api/v1/dispensations/{$id}/authorize")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRANSICION_INVALIDA');

    expect($s->totalStock())->toBe(10);
});

it('autorizar una dispensación no controlada ya completada responde 409', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);
    $id = postDispensation($s->payload(2), (string) Str::uuid())->json('data.id');

    Sanctum::actingAs($s->regente);
    $this->postJson("/api/v1/dispensations/{$id}/authorize")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRANSICION_INVALIDA');
});

it('GET muestra la dispensación al personal de farmacia y al auditor, no al médico', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);
    $id = postDispensation($s->payload(2), (string) Str::uuid())->json('data.id');

    Sanctum::actingAs(User::factory()->auditor()->create());
    $this->getJson("/api/v1/dispensations/{$id}")->assertOk()->assertJsonPath('data.id', $id);

    Sanctum::actingAs(User::factory()->medico()->create());
    $this->getJson("/api/v1/dispensations/{$id}")->assertForbidden();

    Sanctum::actingAs($s->auxiliar);
    $this->getJson('/api/v1/dispensations/999999')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NO_ENCONTRADO');
});

it('auditor y médico no pueden crear dispensaciones', function (string $role) {
    $s = DispensingScenario::make();
    Sanctum::actingAs(User::factory()->{$role}()->create());

    postDispensation($s->payload(1), (string) Str::uuid())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'NO_AUTORIZADO');

    expect(Dispensation::query()->count())->toBe(0);
})->with(['auditor', 'medico', 'admin']);

it('la vista previa FEFO muestra los lotes sin mover stock', function () {
    $s = DispensingScenario::make([[60, 10], [10, 3], [-1, 20]]);
    Sanctum::actingAs($s->auxiliar);

    $this->getJson("/api/v1/dispensations/preview?warehouse_id={$s->warehouse->id}&product_id={$s->product->id}&quantity=5")
        ->assertOk()
        ->assertJsonPath('data.lots.0.lot_id', $s->lots[1]->id)
        ->assertJsonPath('data.lots.0.quantity', 3)
        ->assertJsonPath('data.lots.1.lot_id', $s->lots[0]->id)
        ->assertJsonPath('data.lots.1.quantity', 2)
        ->assertJsonCount(2, 'data.lots');

    $this->getJson("/api/v1/dispensations/preview?warehouse_id={$s->warehouse->id}&product_id={$s->product->id}&quantity=50")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'STOCK_INSUFICIENTE')
        ->assertJsonPath('error.details.available', 13);

    expect($s->totalStock())->toBe(33)
        ->and(KardexMovement::query()->count())->toBe(0);
});

it('S-37: un auxiliar desactivado con token vigente no puede dispensar', function () {
    $s = DispensingScenario::make();
    $s->auxiliar->update(['is_active' => false]);
    Sanctum::actingAs($s->auxiliar);

    postDispensation($s->payload(1), (string) Str::uuid())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'NO_AUTORIZADO');

    expect($s->totalStock())->toBe(10);
});

it('RN-05: auditor y médico no pueden autorizar ni rechazar controlados', function (string $role) {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5, controlled: true);
    Sanctum::actingAs($s->auxiliar);
    $id = postDispensation($s->payload(2), (string) Str::uuid())->json('data.id');

    Sanctum::actingAs(User::factory()->{$role}()->create());
    $this->postJson("/api/v1/dispensations/{$id}/authorize")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'NO_AUTORIZADO');
    $this->postJson("/api/v1/dispensations/{$id}/reject", ['reason' => 'No corresponde'])
        ->assertForbidden();

    expect(Dispensation::query()->findOrFail($id)->status->value)->toBe('PENDIENTE_AUTORIZACION')
        ->and($s->totalStock())->toBe(10);
})->with(['auditor', 'medico']);

it('RN-09: una Idempotency-Key con formato inválido responde 422 y no dispensa', function (string $key) {
    $s = DispensingScenario::make();
    Sanctum::actingAs($s->auxiliar);

    postDispensation($s->payload(1), $key)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION');

    expect(Dispensation::query()->count())->toBe(0)
        ->and($s->totalStock())->toBe(10);
})->with([
    'muy corta' => ['abc'],
    'caracteres no permitidos' => ['clave con espacios'],
    'demasiado larga' => [str_repeat('a', 101)],
]);

it('el auditor no puede usar la vista previa FEFO (es parte de dispensar)', function () {
    $s = DispensingScenario::make();
    Sanctum::actingAs(User::factory()->auditor()->create());

    $this->getJson("/api/v1/dispensations/preview?warehouse_id={$s->warehouse->id}&product_id={$s->product->id}&quantity=1")
        ->assertForbidden();
});
