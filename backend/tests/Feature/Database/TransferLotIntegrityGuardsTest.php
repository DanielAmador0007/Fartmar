<?php

/*
| M2 (revisión de la Fase 3): RN-01 y la recepción única defendidas en la BD,
| faltante coherente con lo despachado/recibido y actores activos.
| SQL directo a propósito: se prueba la BD, no la aplicación.
*/

use App\Models\Lot;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Traslado EN_TRANSITO (llevado por el camino válido) con una línea del
 * producto. Devuelve [transfer_id, transfer_item_id].
 *
 * @return array{0: int, 1: int}
 */
function lotGuardDispatchedItem(Product $product, int $quantity = 10): array
{
    $requester = User::factory()->auxiliar()->create();
    $transferId = DB::table('transfers')->insertGetId([
        'origin_warehouse_id' => Warehouse::factory()->create()->id,
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
        'status' => 'BORRADOR',
        'requested_by' => $requester->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $itemId = DB::table('transfer_items')->insertGetId([
        'transfer_id' => $transferId, 'product_id' => $product->id, 'quantity_requested' => $quantity,
    ]);

    DB::table('transfers')->where('id', $transferId)->update(['status' => 'SOLICITADO', 'requested_at' => now()]);
    DB::table('transfers')->where('id', $transferId)->update([
        'status' => 'APROBADO', 'approved_by' => User::factory()->regente()->create()->id, 'approved_at' => now(),
    ]);
    DB::table('transfers')->where('id', $transferId)->update([
        'status' => 'EN_TRANSITO', 'dispatched_by' => $requester->id, 'dispatched_at' => now(),
    ]);

    return [$transferId, $itemId];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function lotGuardItemLot(int $itemId, Lot $lot, array $overrides = []): int
{
    return DB::table('transfer_item_lots')->insertGetId(array_merge([
        'transfer_item_id' => $itemId,
        'lot_id' => $lot->id,
        'product_id' => $lot->product_id,
        'quantity_dispatched' => 10,
    ], $overrides));
}

it('RN-01 en BD: no se puede despachar un lote vencido (ni ayer); el que vence hoy sí', function () {
    $product = Product::factory()->create();
    [, $itemId] = lotGuardDispatchedItem($product);

    expectDbRejects(
        fn () => lotGuardItemLot($itemId, Lot::factory()->for($product)->expiresInDays(-1)->create()),
        'transfer_item_lots_check_lot',
    );
    expectDbRejects(
        fn () => lotGuardItemLot($itemId, Lot::factory()->for($product)->expiresInDays(-400)->create()),
        'no se puede trasladar (RN-01)',
    );

    // Límite: vence HOY (Bogotá) todavía es válido (vencido = expires_at < hoy).
    $id = lotGuardItemLot($itemId, Lot::factory()->for($product)->expiresInDays(0)->create());
    expect(DB::table('transfer_item_lots')->where('id', $id)->exists())->toBeTrue();
});

it('M2: quantity_received solo pasa de NULL a un valor; no se reescribe', function () {
    $product = Product::factory()->create();
    [, $itemId] = lotGuardDispatchedItem($product);
    $id = lotGuardItemLot($itemId, Lot::factory()->for($product)->create());

    DB::table('transfer_item_lots')->where('id', $id)->update(['quantity_received' => 7]);

    expectDbRejects(fn () => DB::table('transfer_item_lots')->where('id', $id)->update(['quantity_received' => 10]), 'transfer_item_lots_freeze');
    expectDbRejects(fn () => DB::table('transfer_item_lots')->where('id', $id)->update(['quantity_received' => null]), 'ya se registró');

    // Repetir el mismo valor no es un cambio.
    DB::table('transfer_item_lots')->where('id', $id)->update(['quantity_received' => 7, 'updated_at' => now()]);
    expect(DB::table('transfer_item_lots')->where('id', $id)->value('quantity_received'))->toBe(7);
});

it('M2: lo despachado (lote, cantidad) no cambia después de insertarse', function () {
    $product = Product::factory()->create();
    [, $itemId] = lotGuardDispatchedItem($product);
    $id = lotGuardItemLot($itemId, Lot::factory()->for($product)->create());
    $otherLot = Lot::factory()->for($product)->create();

    expectDbRejects(fn () => DB::table('transfer_item_lots')->where('id', $id)->update(['quantity_dispatched' => 3]), 'transfer_item_lots_freeze');
    expectDbRejects(fn () => DB::table('transfer_item_lots')->where('id', $id)->update(['lot_id' => $otherLot->id]), 'transfer_item_lots_freeze');
});

it('M2: el faltante de una discrepancia es exactamente despachado - recibido', function () {
    $product = Product::factory()->create();
    [, $itemId] = lotGuardDispatchedItem($product);
    $id = lotGuardItemLot($itemId, Lot::factory()->for($product)->create());

    // Sin recepción registrada no puede haber discrepancia.
    expectDbRejects(
        fn () => DB::table('transfer_discrepancies')->insert(['transfer_item_lot_id' => $id, 'quantity_missing' => 3]),
        'aún no se ha recibido',
    );

    DB::table('transfer_item_lots')->where('id', $id)->update(['quantity_received' => 7]);

    expectDbRejects(
        fn () => DB::table('transfer_discrepancies')->insert(['transfer_item_lot_id' => $id, 'quantity_missing' => 2]),
        'transfer_discrepancies_check_missing',
    );
    expectDbRejects(
        fn () => DB::table('transfer_discrepancies')->insert(['transfer_item_lot_id' => $id, 'quantity_missing' => 4]),
        'transfer_discrepancies_check_missing',
    );

    $discrepancyId = DB::table('transfer_discrepancies')->insertGetId(['transfer_item_lot_id' => $id, 'quantity_missing' => 3]);

    expectDbRejects(
        fn () => DB::table('transfer_discrepancies')->where('id', $discrepancyId)->update(['quantity_missing' => 1]),
        'transfer_discrepancies_check_missing',
    );
    expect(DB::table('transfer_discrepancies')->where('id', $discrepancyId)->value('quantity_missing'))->toBe(3);
});

it('M2: un regente INACTIVO no aprueba traslados ni resuelve discrepancias (assert_user_role)', function () {
    $inactive = User::factory()->regente()->create(['is_active' => false]);
    $requester = User::factory()->auxiliar()->create();
    $transferId = DB::table('transfers')->insertGetId([
        'origin_warehouse_id' => Warehouse::factory()->create()->id,
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
        'status' => 'SOLICITADO',
        'requested_by' => $requester->id,
        'requested_at' => now(),
    ]);

    expectDbRejects(fn () => DB::table('transfers')->where('id', $transferId)->update([
        'status' => 'APROBADO', 'approved_by' => $inactive->id, 'approved_at' => now(),
    ]), 'debe tener rol regente_farmacia y estar activo');

    $product = Product::factory()->create();
    [, $itemId] = lotGuardDispatchedItem($product);
    $itemLotId = lotGuardItemLot($itemId, Lot::factory()->for($product)->create(), ['quantity_received' => 9]);
    $discrepancyId = DB::table('transfer_discrepancies')->insertGetId(['transfer_item_lot_id' => $itemLotId, 'quantity_missing' => 1]);

    expectDbRejects(fn () => DB::table('transfer_discrepancies')->where('id', $discrepancyId)->update([
        'status' => 'RESUELTA', 'resolution_action' => 'PERDIDA', 'resolution' => 'Rota', 'resolved_by' => $inactive->id, 'resolved_at' => now(),
    ]), 'estar activo');

    // Activo sí.
    $inactive->update(['is_active' => true]);
    DB::table('transfers')->where('id', $transferId)->update([
        'status' => 'APROBADO', 'approved_by' => $inactive->id, 'approved_at' => now(),
    ]);
    expect(DB::table('transfers')->where('id', $transferId)->value('status'))->toBe('APROBADO');
});
