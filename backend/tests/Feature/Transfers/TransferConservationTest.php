<?php

/*
| Conservación de unidades a lo largo de un traslado completo por HTTP
| (RN-06, RN-07, S-50, S-63): en TODO momento
|
|   stock inicial = origen + destino + en tránsito + faltante pendiente + pérdida declarada
|
| y, cuando todas las discrepancias se resuelven, el faltante pendiente es 0.
| Además el kardex de cada existencia cuadra con su saldo y los movimientos
| que dejó el traslado son inmutables.
*/

use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\KardexType;
use App\Models\KardexMovement;
use App\Models\TransferDiscrepancy;
use App\Models\TransferItemLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConcurrentDispenser;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

/**
 * Las cinco "cubetas" donde pueden estar las unidades del producto.
 *
 * @return array{origin: int, destination: int, in_transit: int, pending: int, lost: int}
 */
function unitBuckets(TransferScenario $s): array
{
    // En tránsito según la API (S-63), no según una consulta propia.
    $inTransit = test()->getJson("/api/v1/stocks/in-transit?product_id={$s->product->id}")->assertOk()->json('data');

    return [
        'origin' => $s->totalAt($s->origin),
        'destination' => $s->totalAt($s->destination),
        'in_transit' => array_sum(array_column($inTransit, 'quantity')),
        'pending' => (int) TransferDiscrepancy::query()->where('status', DiscrepancyStatus::Pendiente)->sum('quantity_missing'),
        'lost' => (int) TransferDiscrepancy::query()->where('resolution_action', DiscrepancyResolution::Perdida)->sum('quantity_missing'),
    ];
}

/**
 * Se ejecuta después de CADA paso del flujo.
 *
 * @param  array{origin: int, destination: int, in_transit: int, pending: int, lost: int}  $expected
 */
function expectConserved(TransferScenario $s, array $expected, int $initial): void
{
    $buckets = unitBuckets($s);

    expect($buckets)->toBe($expected)
        ->and(array_sum($buckets))->toBe($initial)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
}

/**
 * Traslado de 8 u. desde un origen con 12 (lote A: 2 u. a 10 días; lote B:
 * 10 u. a 90 días), llevado por HTTP hasta EN_TRANSITO. FEFO: 2 de A y 6 de B.
 *
 * @return array{0: TransferScenario, 1: int}
 */
function conservationDispatched(): array
{
    $s = TransferScenario::make([[10, 2], [90, 10]]);

    Sanctum::actingAs($s->auxiliar);
    expectConserved($s, ['origin' => 12, 'destination' => 0, 'in_transit' => 0, 'pending' => 0, 'lost' => 0], 12);

    $id = test()->postJson('/api/v1/transfers', $s->payload(8))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/transfers/{$id}/submit")->assertOk();
    Sanctum::actingAs($s->regente);
    test()->postJson("/api/v1/transfers/{$id}/approve")->assertOk();
    // Aprobado: aún no se mueve nada.
    expectConserved($s, ['origin' => 12, 'destination' => 0, 'in_transit' => 0, 'pending' => 0, 'lost' => 0], 12);

    Sanctum::actingAs($s->auxiliar);
    test()->postJson("/api/v1/transfers/{$id}/dispatch")->assertOk()->assertJsonPath('data.status', 'EN_TRANSITO');

    return [$s, $id];
}

it('conservación: despacho, recepción parcial y las dos resoluciones (REINTEGRAR_ORIGEN y PERDIDA)', function () {
    [$s, $id] = conservationDispatched();
    [$lotA, $lotB] = $s->lots;

    // Despachado: 8 en tránsito (2 del lote A y 6 del B), por lote en la API.
    test()->getJson('/api/v1/stocks/in-transit')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.transfer_id', $id)
        ->assertJsonPath('data.0.lot.id', $lotA->id)
        ->assertJsonPath('data.0.quantity', 2)
        ->assertJsonPath('data.1.lot.id', $lotB->id)
        ->assertJsonPath('data.1.quantity', 6);
    expectConserved($s, ['origin' => 4, 'destination' => 0, 'in_transit' => 8, 'pending' => 0, 'lost' => 0], 12);

    // Recepción parcial: llega 1 de 2 (A) y 4 de 6 (B). El tránsito se vacía:
    // lo que falta no "sigue viajando", es discrepancia (S-63).
    $itemLots = TransferItemLot::query()->orderBy('id')->get();
    test()->postJson("/api/v1/transfers/{$id}/receive", ['lots' => [
        ['transfer_item_lot_id' => $itemLots[0]->id, 'quantity_received' => 1],
        ['transfer_item_lot_id' => $itemLots[1]->id, 'quantity_received' => 4],
    ]])->assertOk()->assertJsonPath('data.status', 'RECIBIDO_PARCIAL');

    test()->getJson('/api/v1/stocks/in-transit')->assertOk()->assertJsonCount(0, 'data');
    expectConserved($s, ['origin' => 4, 'destination' => 5, 'in_transit' => 0, 'pending' => 3, 'lost' => 0], 12);

    $discrepancyA = TransferDiscrepancy::query()->where('transfer_item_lot_id', $itemLots[0]->id)->sole();
    $discrepancyB = TransferDiscrepancy::query()->where('transfer_item_lot_id', $itemLots[1]->id)->sole();

    // A: la unidad nunca salió -> vuelve al origen con AJUSTE (+1).
    Sanctum::actingAs($s->regente);
    test()->postJson("/api/v1/transfer-discrepancies/{$discrepancyA->id}/resolve", [
        'action' => 'REINTEGRAR_ORIGEN', 'resolution' => 'Quedó en el estante del origen',
    ])->assertOk();
    expectConserved($s, ['origin' => 5, 'destination' => 5, 'in_transit' => 0, 'pending' => 2, 'lost' => 0], 12);

    // B: 2 unidades se dañaron en el camino -> pérdida declarada, sin mover stock.
    Sanctum::actingAs($s->regente2);
    test()->postJson("/api/v1/transfer-discrepancies/{$discrepancyB->id}/resolve", [
        'action' => 'PERDIDA', 'resolution' => 'Caja aplastada en el transporte',
    ])->assertOk();

    // Final: inicial = origen + destino + en tránsito + pérdida declarada.
    expectConserved($s, ['origin' => 5, 'destination' => 5, 'in_transit' => 0, 'pending' => 0, 'lost' => 2], 12);

    // Lo mismo visto solo desde el kardex: neto de todos los movimientos del
    // producto = unidades que existen; la diferencia con lo que entró es la pérdida.
    $net = (int) KardexMovement::query()->where('product_id', $s->product->id)->sum(DB::raw('direction * quantity'));
    $byType = KardexMovement::query()->where('product_id', $s->product->id)
        ->selectRaw('type, SUM(direction * quantity) AS units')->groupBy('type')->pluck('units', 'type')
        ->map(fn ($u) => (int) $u)->sortKeys()->all();

    expect($net)->toBe(10)
        ->and($byType)->toBe([
            KardexType::Ajuste->value => 1,
            KardexType::Entrada->value => 12,
            KardexType::EntradaTraslado->value => 5,
            KardexType::SalidaTraslado->value => -8,
        ]);
});

it('RN-06: los movimientos que deja un traslado (SALIDA_TRASLADO, ENTRADA_TRASLADO, AJUSTE de reintegro) son inmutables', function () {
    [$s, $id] = conservationDispatched();
    $itemLot = TransferItemLot::query()->orderBy('id')->firstOrFail();
    $others = TransferItemLot::query()->where('id', '<>', $itemLot->id)->get();

    test()->postJson("/api/v1/transfers/{$id}/receive", ['lots' => [
        ['transfer_item_lot_id' => $itemLot->id, 'quantity_received' => 1],
        ...$others->map(fn ($l) => ['transfer_item_lot_id' => $l->id, 'quantity_received' => $l->quantity_dispatched])->all(),
    ]])->assertOk();

    Sanctum::actingAs($s->regente);
    test()->postJson('/api/v1/transfer-discrepancies/'.TransferDiscrepancy::query()->sole()->id.'/resolve', [
        'action' => 'REINTEGRAR_ORIGEN', 'resolution' => 'No salió del origen',
    ])->assertOk();

    $types = [KardexType::SalidaTraslado, KardexType::EntradaTraslado, KardexType::Ajuste];
    foreach ($types as $type) {
        $movement = KardexMovement::query()->where('type', $type)->orderBy('id')->firstOrFail();
        $before = (array) DB::table('kardex_movements')->where('id', $movement->id)->first();

        expectDbRejects(fn () => DB::update('UPDATE kardex_movements SET quantity = quantity + 1 WHERE id = ?', [$movement->id]), 'solo inserción');
        expectDbRejects(fn () => DB::update('UPDATE kardex_movements SET reference_id = NULL, reference_type = NULL WHERE id = ?', [$movement->id]), 'solo inserción');
        expectDbRejects(fn () => DB::delete('DELETE FROM kardex_movements WHERE id = ?', [$movement->id]), 'solo inserción');
        expectDbRejects(fn () => $movement->delete(), 'solo inserción');

        expect((array) DB::table('kardex_movements')->where('id', $movement->id)->first())->toBe($before);
    }

    expect(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});
