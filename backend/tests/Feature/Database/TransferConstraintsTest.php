<?php

/*
| Constraints de traslados (RN-07, RN-08).
*/

use App\Models\Lot;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transferRow(array $overrides = []): array
{
    return array_merge([
        'origin_warehouse_id' => Warehouse::factory()->create()->id,
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
        'status' => 'BORRADOR',
        'requested_by' => User::factory()->auxiliar()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('RN-08: quien solicita un traslado no puede aprobarlo', function () {
    $regente = User::factory()->regente()->create();
    $id = DB::table('transfers')->insertGetId(transferRow([
        'requested_by' => $regente->id, 'status' => 'SOLICITADO', 'requested_at' => now(),
    ]));

    expectDbRejects(fn () => DB::table('transfers')->where('id', $id)->update([
        'status' => 'APROBADO', 'approved_by' => $regente->id, 'approved_at' => now(),
    ]), 'transfers_approver_differs');

    // Otro regente sí puede.
    DB::table('transfers')->where('id', $id)->update([
        'status' => 'APROBADO', 'approved_by' => User::factory()->regente()->create()->id, 'approved_at' => now(),
    ]);
    expect(DB::table('transfers')->where('id', $id)->value('status'))->toBe('APROBADO');
});

it('solo un regente_farmacia puede aprobar traslados (trigger)', function () {
    expectDbRejects(fn () => DB::table('transfers')->insert(transferRow([
        'status' => 'APROBADO',
        'requested_at' => now(),
        'approved_by' => User::factory()->auxiliar()->create()->id,
        'approved_at' => now(),
    ])), 'debe tener rol regente_farmacia');
});

it('rechaza traslados a la misma bodega y estados inválidos', function () {
    $warehouse = Warehouse::factory()->create();

    expectDbRejects(fn () => DB::table('transfers')->insert(transferRow([
        'origin_warehouse_id' => $warehouse->id, 'destination_warehouse_id' => $warehouse->id,
    ])), 'transfers_distinct_warehouses');

    expectDbRejects(
        fn () => DB::table('transfers')->insert(transferRow(['status' => 'PERDIDO'])),
        'transfers_status_check',
    );
});

it('RN-07: cada estado exige sus actores registrados', function (array $overrides, string $constraint) {
    // Los usuarios no pueden crearse al definir el dataset (aún no hay BD).
    foreach (['approved_by', 'dispatched_by'] as $column) {
        if (($overrides[$column] ?? null) === 'REGENTE') {
            $overrides[$column] = User::factory()->regente()->create()->id;
        }
    }

    expectDbRejects(fn () => DB::table('transfers')->insert(transferRow($overrides)), $constraint);
})->with([
    'solicitado sin fecha' => [['status' => 'SOLICITADO'], 'transfers_requested_complete'],
    'aprobado sin aprobador' => [['status' => 'APROBADO', 'requested_at' => '2026-01-01 10:00:00'], 'transfers_approved_complete'],
    'en tránsito sin despachador' => [[
        'status' => 'EN_TRANSITO',
        'requested_at' => '2026-01-01 10:00:00',
        'approved_at' => '2026-01-01 11:00:00',
        'approved_by' => 'REGENTE',
    ], 'transfers_dispatched_complete'],
    'recibido sin receptor' => [[
        'status' => 'RECIBIDO',
        'requested_at' => '2026-01-01 10:00:00',
        'approved_at' => '2026-01-01 11:00:00',
        'approved_by' => 'REGENTE',
        'dispatched_at' => '2026-01-01 12:00:00',
        'dispatched_by' => 'REGENTE',
    ], 'transfers_received_complete'],
]);

it('RN-07: no se puede anular un traslado ya despachado', function () {
    $regente = User::factory()->regente()->create();

    expectDbRejects(fn () => DB::table('transfers')->insert(transferRow([
        'status' => 'ANULADO',
        'requested_at' => now(),
        'approved_by' => $regente->id,
        'approved_at' => now(),
        'dispatched_by' => $regente->id,
        'dispatched_at' => now(),
        'cancelled_by' => $regente->id,
        'cancelled_at' => now(),
    ])), 'transfers_cancel_before_dispatch');
});

it('rechaza recibir más de lo despachado y discrepancias inválidas', function () {
    $transferId = DB::table('transfers')->insertGetId(transferRow());
    $product = Product::factory()->create();
    $lot = Lot::factory()->create(['product_id' => $product->id]);
    $itemId = DB::table('transfer_items')->insertGetId([
        'transfer_id' => $transferId, 'product_id' => $product->id, 'quantity_requested' => 10,
    ]);
    $itemLotId = DB::table('transfer_item_lots')->insertGetId([
        'transfer_item_id' => $itemId, 'lot_id' => $lot->id, 'product_id' => $product->id, 'quantity_dispatched' => 10,
    ]);

    expectDbRejects(
        fn () => DB::table('transfer_item_lots')->where('id', $itemLotId)->update(['quantity_received' => 11]),
        'transfer_item_lots_received_range',
    );
    expectDbRejects(
        fn () => DB::table('transfer_items')->where('id', $itemId)->update(['quantity_requested' => 0]),
        'transfer_items_quantity_requested_positive',
    );
    expectDbRejects(
        fn () => DB::table('transfer_discrepancies')->insert(['transfer_item_lot_id' => $itemLotId, 'quantity_missing' => 0]),
        'transfer_discrepancies_missing_positive',
    );
    expectDbRejects(fn () => DB::table('transfer_discrepancies')->insert([
        'transfer_item_lot_id' => $itemLotId, 'quantity_missing' => 2, 'status' => 'RESUELTA',
    ]), 'transfer_discrepancies_resolution_complete');

    // Recepción parcial válida + discrepancia pendiente.
    DB::table('transfer_item_lots')->where('id', $itemLotId)->update(['quantity_received' => 8]);
    DB::table('transfer_discrepancies')->insert(['transfer_item_lot_id' => $itemLotId, 'quantity_missing' => 2]);
    expect(DB::table('transfer_discrepancies')->value('status'))->toBe('PENDIENTE');
});

/*
| Huecos y casos límite agregados en la revisión de QA (Fase 1, cierre).
*/

/**
 * Traslado ya despachado (EN_TRANSITO) con todos sus actores.
 */
function insertDispatchedTransfer(): int
{
    return DB::table('transfers')->insertGetId(transferRow([
        'status' => 'EN_TRANSITO',
        'requested_at' => now(),
        'approved_by' => User::factory()->regente()->create()->id,
        'approved_at' => now(),
        'dispatched_by' => User::factory()->auxiliar()->create()->id,
        'dispatched_at' => now(),
    ]));
}

it('RN-07: un traslado EN_TRANSITO no se puede pasar a ANULADO por UPDATE', function () {
    $id = insertDispatchedTransfer();

    expectDbRejects(fn () => DB::table('transfers')->where('id', $id)->update([
        'status' => 'ANULADO',
        'cancelled_by' => User::factory()->regente()->create()->id,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Error de digitación',
    ]), 'transfers_cancel_before_dispatch');

    expect(DB::table('transfers')->where('id', $id)->value('status'))->toBe('EN_TRANSITO');
});

it('RN-07: anular antes del despacho es válido, pero exige quién y cuándo', function () {
    $regente = User::factory()->regente()->create();
    $id = DB::table('transfers')->insertGetId(transferRow([
        'status' => 'APROBADO', 'requested_at' => now(), 'approved_by' => $regente->id, 'approved_at' => now(),
    ]));

    expectDbRejects(
        fn () => DB::table('transfers')->where('id', $id)->update(['status' => 'ANULADO']),
        'transfers_cancel_before_dispatch',
    );

    DB::table('transfers')->where('id', $id)->update([
        'status' => 'ANULADO', 'cancelled_by' => $regente->id, 'cancelled_at' => now(),
    ]);
    expect(DB::table('transfers')->where('id', $id)->value('status'))->toBe('ANULADO');
});

it('RN-08: el trigger de rol también protege la aprobación por UPDATE', function () {
    $id = DB::table('transfers')->insertGetId(transferRow(['status' => 'SOLICITADO', 'requested_at' => now()]));

    expectDbRejects(fn () => DB::table('transfers')->where('id', $id)->update([
        'status' => 'APROBADO', 'approved_by' => User::factory()->auxiliar()->create()->id, 'approved_at' => now(),
    ]), 'debe tener rol regente_farmacia');
});

it('RN-07: RECIBIDO_PARCIAL también exige receptor', function () {
    $id = insertDispatchedTransfer();

    expectDbRejects(
        fn () => DB::table('transfers')->where('id', $id)->update(['status' => 'RECIBIDO_PARCIAL']),
        'transfers_received_complete',
    );

    DB::table('transfers')->where('id', $id)->update([
        'status' => 'RECIBIDO_PARCIAL', 'received_by' => User::factory()->auxiliar()->create()->id, 'received_at' => now(),
    ]);
    expect(DB::table('transfers')->where('id', $id)->value('status'))->toBe('RECIBIDO_PARCIAL');
});

it('líneas y lotes de traslado: sin duplicados, lote del producto y cantidades en rango', function () {
    $transferId = insertDispatchedTransfer();
    $product = Product::factory()->create();
    $lot = Lot::factory()->create(['product_id' => $product->id]);
    $itemId = DB::table('transfer_items')->insertGetId([
        'transfer_id' => $transferId, 'product_id' => $product->id, 'quantity_requested' => 5,
    ]);

    expectDbRejects(fn () => DB::table('transfer_items')->insert([
        'transfer_id' => $transferId, 'product_id' => $product->id, 'quantity_requested' => 1,
    ]), 'transfer_items_transfer_id_product_id_unique');

    $lotOfOtherProduct = Lot::factory()->create();
    expectDbRejects(fn () => DB::table('transfer_item_lots')->insert([
        'transfer_item_id' => $itemId, 'lot_id' => $lotOfOtherProduct->id, 'product_id' => $product->id, 'quantity_dispatched' => 1,
    ]), 'transfer_item_lots_lot_id_product_id_foreign');
    expectDbRejects(fn () => DB::table('transfer_item_lots')->insert([
        'transfer_item_id' => $itemId, 'lot_id' => $lotOfOtherProduct->id, 'product_id' => $lotOfOtherProduct->product_id, 'quantity_dispatched' => 1,
    ]), 'transfer_item_lots_transfer_item_id_product_id_foreign');

    $row = ['transfer_item_id' => $itemId, 'lot_id' => $lot->id, 'product_id' => $product->id];
    expectDbRejects(fn () => DB::table('transfer_item_lots')->insert($row + ['quantity_dispatched' => 0]), 'transfer_item_lots_dispatched_positive');

    $itemLotId = DB::table('transfer_item_lots')->insertGetId($row + ['quantity_dispatched' => 5]);
    expectDbRejects(fn () => DB::table('transfer_item_lots')->insert($row + ['quantity_dispatched' => 1]), 'transfer_item_lots_transfer_item_id_lot_id_unique');
    expectDbRejects(
        fn () => DB::table('transfer_item_lots')->where('id', $itemLotId)->update(['quantity_received' => -1]),
        'transfer_item_lots_received_range',
    );

    // Límites válidos: recibir 0 (todo perdido) y recibir exactamente lo despachado.
    DB::table('transfer_item_lots')->where('id', $itemLotId)->update(['quantity_received' => 0]);
    DB::table('transfer_item_lots')->where('id', $itemLotId)->update(['quantity_received' => 5]);
    expect(DB::table('transfer_item_lots')->where('id', $itemLotId)->value('quantity_received'))->toBe(5);
});

it('discrepancias: una por lote despachado, estado válido y resolución completa', function () {
    $transferId = insertDispatchedTransfer();
    $product = Product::factory()->create();
    $itemId = DB::table('transfer_items')->insertGetId([
        'transfer_id' => $transferId, 'product_id' => $product->id, 'quantity_requested' => 4,
    ]);
    $itemLotId = DB::table('transfer_item_lots')->insertGetId([
        'transfer_item_id' => $itemId,
        'lot_id' => Lot::factory()->create(['product_id' => $product->id])->id,
        'product_id' => $product->id,
        'quantity_dispatched' => 4,
        'quantity_received' => 3,
    ]);

    expectDbRejects(fn () => DB::table('transfer_discrepancies')->insert([
        'transfer_item_lot_id' => $itemLotId, 'quantity_missing' => 1, 'status' => 'OLVIDADA',
    ]), 'transfer_discrepancies_status_check');

    $discrepancyId = DB::table('transfer_discrepancies')->insertGetId(['transfer_item_lot_id' => $itemLotId, 'quantity_missing' => 1]);

    expectDbRejects(
        fn () => DB::table('transfer_discrepancies')->insert(['transfer_item_lot_id' => $itemLotId, 'quantity_missing' => 1]),
        'transfer_discrepancies_transfer_item_lot_id_unique',
    );
    expectDbRejects(fn () => DB::table('transfer_discrepancies')->where('id', $discrepancyId)->update([
        'status' => 'RESUELTA', 'resolved_by' => User::factory()->regente()->create()->id, 'resolved_at' => now(), 'resolution' => ' ',
    ]), 'transfer_discrepancies_resolution_complete');

    DB::table('transfer_discrepancies')->where('id', $discrepancyId)->update([
        'status' => 'RESUELTA',
        'resolved_by' => User::factory()->regente()->create()->id,
        'resolved_at' => now(),
        'resolution' => 'Unidad rota en transporte; se registra AJUSTE',
    ]);
    expect(DB::table('transfer_discrepancies')->where('id', $discrepancyId)->value('status'))->toBe('RESUELTA');
});
