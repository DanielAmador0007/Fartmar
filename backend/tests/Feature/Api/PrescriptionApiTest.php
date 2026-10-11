<?php

/*
| POST /api/v1/prescriptions (solo médico) y su uso inmediato en una
| dispensación (RN-04).
*/

use App\Domain\Inventory\BusinessDate;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function prescriptionPayload(Patient $patient, Product $product, array $overrides = []): array
{
    return array_replace([
        'patient_id' => $patient->id,
        'valid_until' => BusinessDate::today()->addDays(30)->toDateString(),
        'items' => [['product_id' => $product->id, 'quantity' => 6, 'instructions' => '1 tableta cada 8 horas']],
    ], $overrides);
}

it('el médico crea una prescripción ACTIVA, queda auditada sin texto libre y se puede dispensar', function () {
    $s = DispensingScenario::make([[30, 10]]);
    $medico = User::factory()->medico()->create();
    Sanctum::actingAs($medico);

    $response = $this->postJson('/api/v1/prescriptions', prescriptionPayload($s->patient, $s->product))
        ->assertCreated()
        ->assertJsonPath('data.patient_id', $s->patient->id)
        ->assertJsonPath('data.status', 'ACTIVA')
        ->assertJsonPath('data.is_current', true)
        ->assertJsonPath('data.prescriber.id', $medico->id)
        ->assertJsonPath('data.items.0.quantity_prescribed', 6)
        ->assertJsonPath('data.items.0.quantity_available', 6)
        ->assertJsonPath('data.items.0.instructions', '1 tableta cada 8 horas');

    $prescription = Prescription::query()->findOrFail($response->json('data.id'));
    expect($prescription->number)->toStartWith('RX-')
        ->and(strlen($prescription->number))->toBeLessThanOrEqual(30);

    $audit = AuditLog::query()->where('action', 'prescription.created')->sole();
    expect($audit->user_id)->toBe($medico->id)
        ->and($audit->auditable_id)->toBe($prescription->id)
        ->and(json_encode($audit->metadata))->not->toContain('tableta');

    // El auxiliar la usa de inmediato.
    Sanctum::actingAs($s->auxiliar);
    $this->postJson('/api/v1/dispensations', [
        'patient_id' => $s->patient->id,
        'prescription_id' => $prescription->id,
        'warehouse_id' => $s->warehouse->id,
        'items' => [['prescription_item_id' => $response->json('data.items.0.id'), 'quantity' => 6]],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.status', 'COMPLETADA');
});

it('valida vigencia, cantidades y productos de la prescripción', function (Closure $overrides, string $field) {
    $s = DispensingScenario::make();
    Sanctum::actingAs(User::factory()->medico()->create());

    $this->postJson('/api/v1/prescriptions', prescriptionPayload($s->patient, $s->product, $overrides($s)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field, 'error.details.fields');

    expect(Prescription::query()->count())->toBe(1); // solo la del escenario
})->with([
    'vigente solo hoy (S-70)' => [fn () => ['valid_until' => BusinessDate::today()->toDateString()], 'valid_until'],
    'vencida' => [fn () => ['valid_until' => BusinessDate::today()->subDay()->toDateString()], 'valid_until'],
    'más de un año' => [fn () => ['valid_until' => BusinessDate::today()->addDays(366)->toDateString()], 'valid_until'],
    'fecha con hora' => [fn () => ['valid_until' => BusinessDate::today()->addDays(5)->toIso8601String()], 'valid_until'],
    'sin líneas' => [fn () => ['items' => []], 'items'],
    'cantidad cero' => [fn (DispensingScenario $s) => ['items' => [['product_id' => $s->product->id, 'quantity' => 0]]], 'items.0.quantity'],
    'producto repetido' => [fn (DispensingScenario $s) => ['items' => [
        ['product_id' => $s->product->id, 'quantity' => 1],
        ['product_id' => $s->product->id, 'quantity' => 2],
    ]], 'items.1.product_id'],
    'producto inexistente' => [fn () => ['items' => [['product_id' => 999999, 'quantity' => 1]]], 'items.0.product_id'],
    'paciente inexistente' => [fn () => ['patient_id' => 999999], 'patient_id'],
    'claves extra en la línea' => [fn (DispensingScenario $s) => ['items' => [['product_id' => $s->product->id, 'quantity' => 1, 'quantity_dispensed' => 1]]], 'items.0'],
]);
