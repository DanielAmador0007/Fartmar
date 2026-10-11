<?php

/*
| API de ajustes de inventario y consulta de kardex (RN-06).
*/

use App\Domain\Transfers\TransferService;
use App\Enums\DiscrepancyResolution;
use App\Enums\KardexType;
use App\Models\KardexMovement;
use App\Models\TransferDiscrepancy;
use App\Models\TransferItemLot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

it('POST inventory-adjustments (regente) responde 201 con el movimiento AJUSTE', function () {
    $s = TransferScenario::make([[30, 10]]);
    Sanctum::actingAs($s->regente);

    test()->postJson('/api/v1/inventory-adjustments', [
        'warehouse_id' => $s->origin->id,
        'lot_id' => $s->lots[0]->id,
        'quantity' => -3,
        'reason' => 'Unidades averiadas en conteo',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'AJUSTE')
        ->assertJsonPath('data.direction', -1)
        ->assertJsonPath('data.quantity', 3)
        ->assertJsonPath('data.signed_quantity', -3)
        ->assertJsonPath('data.balance_after', 7)
        ->assertJsonPath('data.reason', 'Unidades averiadas en conteo')
        ->assertJsonPath('data.reference', null)
        ->assertJsonPath('data.user.id', $s->regente->id);

    expect($s->stockAt($s->origin, $s->lots[0]))->toBe(7);
});

it('RN-06: ajuste sin motivo o con cantidad 0 responde 422 y no mueve stock', function (array $override, string $field) {
    $s = TransferScenario::make([[30, 10]]);
    Sanctum::actingAs($s->regente);

    test()->postJson('/api/v1/inventory-adjustments', $override + [
        'warehouse_id' => $s->origin->id,
        'lot_id' => $s->lots[0]->id,
        'quantity' => 2,
        'reason' => 'Conteo físico',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

    expect(KardexMovement::query()->where('type', KardexType::Ajuste)->count())->toBe(0);
})->with([
    'sin motivo' => [['reason' => null], 'reason'],
    'motivo vacío' => [['reason' => ''], 'reason'],
    'cantidad cero' => [['quantity' => 0], 'quantity'],
]);

it('RN-03: ajuste negativo mayor que la existencia responde 409 STOCK_INSUFICIENTE', function () {
    $s = TransferScenario::make([[30, 2]]);
    Sanctum::actingAs($s->regente);

    test()->postJson('/api/v1/inventory-adjustments', [
        'warehouse_id' => $s->origin->id, 'lot_id' => $s->lots[0]->id, 'quantity' => -5, 'reason' => 'Conteo físico',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'STOCK_INSUFICIENTE');

    expect($s->stockAt($s->origin, $s->lots[0]))->toBe(2);
});

it('un auxiliar no puede ajustar (403)', function () {
    $s = TransferScenario::make([[30, 10]]);
    Sanctum::actingAs($s->auxiliar);

    test()->postJson('/api/v1/inventory-adjustments', [
        'warehouse_id' => $s->origin->id, 'lot_id' => $s->lots[0]->id, 'quantity' => 1, 'reason' => 'Conteo físico',
    ])->assertForbidden()->assertJsonPath('error.code', 'NO_AUTORIZADO');
});

it('GET kardex: del más reciente al más antiguo, con saldo y referencia al documento', function () {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = $s->dispatched(4);

    Sanctum::actingAs(User::factory()->auditor()->create());

    test()->getJson("/api/v1/kardex?warehouse_id={$s->origin->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'SALIDA_TRASLADO')
        ->assertJsonPath('data.0.signed_quantity', -4)
        ->assertJsonPath('data.0.balance_after', 6)
        ->assertJsonPath('data.0.reference', ['type' => 'transfer', 'id' => $transfer->id])
        ->assertJsonPath('data.0.lot.id', $s->lots[0]->id)
        ->assertJsonPath('data.1.type', 'ENTRADA')
        ->assertJsonPath('data.1.balance_after', 10)
        ->assertJsonPath('meta.total', 2);
});

it('GET kardex filtra por producto, lote, bodega y tipo', function () {
    $s = TransferScenario::make([[30, 10], [60, 5]]);
    $other = TransferScenario::make([[30, 7]]);
    $s->dispatched(12);

    Sanctum::actingAs($s->auxiliar);

    test()->getJson("/api/v1/kardex?product_id={$s->product->id}")->assertOk()->assertJsonCount(4, 'data');
    test()->getJson("/api/v1/kardex?lot_id={$s->lots[1]->id}")->assertOk()->assertJsonCount(2, 'data');
    test()->getJson("/api/v1/kardex?product_id={$s->product->id}&type=SALIDA_TRASLADO")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.lot.id', $s->lots[1]->id)
        ->assertJsonPath('data.1.lot.id', $s->lots[0]->id);
    test()->getJson("/api/v1/kardex?warehouse_id={$other->origin->id}")->assertOk()->assertJsonCount(1, 'data');
    test()->getJson('/api/v1/kardex?type=OTRO')->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
});

it('GET kardex filtra por rango de fechas en hora de Bogotá y pagina', function () {
    $s = TransferScenario::make();

    // 2026-03-10 23:30 en Bogotá = 2026-03-11 04:30 UTC: cuenta para el día 10.
    $this->travelTo(CarbonImmutable::parse('2026-03-10 23:30', 'America/Bogota'));
    $lateLot = $s->addLot($s->product, 300, 1);
    $this->travelTo(CarbonImmutable::parse('2026-03-11 08:00', 'America/Bogota'));
    $s->addLot($s->product, 300, 1);
    $this->travelBack();

    Sanctum::actingAs($s->auxiliar);

    test()->getJson('/api/v1/kardex?from=2026-03-10&to=2026-03-10')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lot.id', $lateLot->id);

    test()->getJson('/api/v1/kardex?from=2026-03-10&to=2026-03-11')->assertOk()->assertJsonCount(2, 'data');
    test()->getJson('/api/v1/kardex?from=2026-03-12&to=2026-03-10')->assertStatus(422);

    test()->getJson('/api/v1/kardex?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 3);
});

it('L5: GET kardex filtra por documento de referencia (reference_type + reference_id)', function () {
    $s = TransferScenario::make([[30, 10]]);
    $transfer = $s->dispatched(5);
    $s->dispatched(1); // Otro traslado: su salida no debe aparecer al filtrar por id.
    $service = app(TransferService::class);
    $lotId = TransferItemLot::query()->whereIn('transfer_item_id', $transfer->items()->select('id'))->value('id');
    $service->receive($transfer, $s->auxiliar, [$lotId => 3]);
    $discrepancy = TransferDiscrepancy::query()->sole();
    $service->resolveDiscrepancy($discrepancy, $s->regente2, DiscrepancyResolution::ReintegrarOrigen, 'Quedaron en el origen');

    Sanctum::actingAs(User::factory()->auditor()->create());

    // Del traslado: salida en origen y entrada en destino (no los del otro traslado).
    $types = collect(test()->getJson("/api/v1/kardex?reference_type=transfer&reference_id={$transfer->id}")->assertOk()->json('data'))
        ->map(fn (array $m) => [$m['type'], $m['warehouse']['id'], $m['reference']['id']])
        ->all();
    expect($types)->toEqualCanonicalizing([
        ['SALIDA_TRASLADO', $s->origin->id, $transfer->id],
        ['ENTRADA_TRASLADO', $s->destination->id, $transfer->id],
    ]);

    // De la discrepancia: el AJUSTE de reintegro.
    test()->getJson("/api/v1/kardex?reference_type=transfer_discrepancy&reference_id={$discrepancy->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'AJUSTE')
        ->assertJsonPath('data.0.signed_quantity', 2);

    // Solo el tipo: todos los movimientos de traslados (3 de los dos traslados).
    test()->getJson('/api/v1/kardex?reference_type=transfer')->assertOk()->assertJsonCount(3, 'data');
});

it('L5: reference_type debe ser un documento conocido y reference_id exige el tipo (422)', function (string $query, string $field) {
    Sanctum::actingAs(User::factory()->auditor()->create());

    test()->getJson("/api/v1/kardex?{$query}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
})->with([
    'tipo desconocido' => ['reference_type=user', 'reference_type'],
    'clase PHP en vez de alias' => ['reference_type=App%5CModels%5CTransfer', 'reference_type'],
    'id sin tipo' => ['reference_id=5', 'reference_type'],
    'id inválido' => ['reference_type=transfer&reference_id=0', 'reference_id'],
]);
