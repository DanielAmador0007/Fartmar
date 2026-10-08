<?php

/*
| Constraints de inventario y kardex (RN-01, RN-03, RN-06).
| Se escribe con SQL/Query Builder a propósito: se prueba que la BASE DE DATOS
| rechaza la operación aunque el código de la aplicación tuviera un bug.
*/

use App\Models\KardexMovement;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kardexRow(Stock $stock, array $overrides = []): array
{
    return array_merge([
        'warehouse_id' => $stock->warehouse_id,
        'product_id' => $stock->product_id,
        'lot_id' => $stock->lot_id,
        'type' => 'ENTRADA',
        'quantity' => 5,
        'direction' => 1,
        'balance_after' => 5,
        'user_id' => User::factory()->create()->id,
    ], $overrides);
}

it('RN-03: rechaza stock negativo al insertar y al descontar por SQL', function () {
    $stock = Stock::factory()->create(['quantity' => 10]);

    expectDbRejects(fn () => DB::table('stocks')->insert([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'lot_id' => $stock->lot_id,
        'product_id' => $stock->product_id,
        'quantity' => -1,
    ]), 'stocks_quantity_non_negative');

    expectDbRejects(
        fn () => DB::update('UPDATE stocks SET quantity = quantity - 11 WHERE id = ?', [$stock->id]),
        'stocks_quantity_non_negative',
    );

    expect($stock->fresh()?->quantity)->toBe(10);
});

it('RN-01: una existencia es única por bodega + lote', function () {
    $stock = Stock::factory()->create();

    expectDbRejects(fn () => DB::table('stocks')->insert([
        'warehouse_id' => $stock->warehouse_id,
        'lot_id' => $stock->lot_id,
        'product_id' => $stock->product_id,
        'quantity' => 1,
    ]), 'stocks_warehouse_id_lot_id_unique');
});

it('rechaza un product_id en stocks que no corresponde al producto del lote', function () {
    $lot = Lot::factory()->create();
    $otherProduct = Product::factory()->create();

    expectDbRejects(fn () => DB::table('stocks')->insert([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'lot_id' => $lot->id,
        'product_id' => $otherProduct->id,
        'quantity' => 1,
    ]), 'stocks_lot_id_product_id_foreign');
});

it('rechaza números de lote duplicados para el mismo producto', function () {
    $lot = Lot::factory()->create();

    expectDbRejects(fn () => DB::table('lots')->insert([
        'product_id' => $lot->product_id,
        'lot_number' => $lot->lot_number,
        'expires_at' => '2030-01-01',
    ]), 'lots_product_id_lot_number_unique');
});

it('rechaza stock mínimo negativo', function () {
    expectDbRejects(fn () => DB::table('stock_minimums')->insert([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'min_quantity' => -5,
    ]), 'stock_minimums_min_quantity_non_negative');
});

it('RN-06: el kardex es inmutable (UPDATE y DELETE rechazados por trigger)', function () {
    $stock = Stock::factory()->create(['quantity' => 5]);
    $movement = KardexMovement::query()->create(kardexRow($stock));

    expectDbRejects(
        fn () => DB::update('UPDATE kardex_movements SET quantity = 999 WHERE id = ?', [$movement->id]),
        'solo inserción',
    );
    expectDbRejects(
        fn () => DB::delete('DELETE FROM kardex_movements WHERE id = ?', [$movement->id]),
        'solo inserción',
    );
    // También por Eloquent.
    expectDbRejects(fn () => $movement->update(['reason' => 'corrección']), 'solo inserción');
    expectDbRejects(fn () => $movement->delete(), 'solo inserción');

    expect(KardexMovement::query()->findOrFail($movement->id)->quantity)->toBe(5);
});

it('RN-06: un AJUSTE exige motivo no vacío', function () {
    $stock = Stock::factory()->create();

    expectDbRejects(
        fn () => DB::table('kardex_movements')->insert(kardexRow($stock, ['type' => 'AJUSTE', 'reason' => null])),
        'kardex_movements_adjustment_reason_required',
    );
    expectDbRejects(
        fn () => DB::table('kardex_movements')->insert(kardexRow($stock, ['type' => 'AJUSTE', 'reason' => '   '])),
        'kardex_movements_adjustment_reason_required',
    );

    DB::table('kardex_movements')->insert(kardexRow($stock, [
        'type' => 'AJUSTE', 'direction' => -1, 'reason' => 'Conteo físico: unidad rota',
    ]));
    expect(KardexMovement::query()->count())->toBe(1);
});

it('RN-06: valida tipo, cantidad, dirección y saldo del movimiento', function (array $overrides, string $constraint) {
    $stock = Stock::factory()->create();

    expectDbRejects(
        fn () => DB::table('kardex_movements')->insert(kardexRow($stock, $overrides)),
        $constraint,
    );
})->with([
    'tipo inválido' => [['type' => 'REGALO'], 'kardex_movements_type_check'],
    'cantidad cero' => [['quantity' => 0], 'kardex_movements_quantity_positive'],
    'cantidad negativa' => [['quantity' => -3], 'kardex_movements_quantity_positive'],
    'dirección inválida' => [['direction' => 2], 'kardex_movements_direction_check'],
    'saldo negativo' => [['balance_after' => -1], 'kardex_movements_balance_non_negative'],
    'salida que suma' => [['type' => 'SALIDA_DISPENSACION', 'direction' => 1], 'kardex_movements_direction_matches_type'],
    'entrada que resta' => [['type' => 'ENTRADA_TRASLADO', 'direction' => -1], 'kardex_movements_direction_matches_type'],
]);

it('rechaza movimientos de kardex sin existencia (bodega + lote) asociada', function () {
    $stock = Stock::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();

    expectDbRejects(
        fn () => DB::table('kardex_movements')->insert(kardexRow($stock, ['warehouse_id' => $otherWarehouse->id])),
        'kardex_movements_warehouse_id_lot_id_foreign',
    );
});

/*
| Huecos y casos límite agregados en la revisión de QA (Fase 1, cierre).
*/

it('rechaza códigos de catálogo vacíos o duplicados', function () {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();

    expectDbRejects(fn () => DB::table('warehouses')->insert(['code' => '  ', 'name' => 'X']), 'warehouses_code_not_blank');
    expectDbRejects(fn () => DB::table('warehouses')->insert(['code' => $warehouse->code, 'name' => 'X']), 'warehouses_code_unique');
    expectDbRejects(fn () => DB::table('products')->insert([
        'code' => '', 'name' => 'X', 'presentation' => 'X', 'unit' => 'tableta',
    ]), 'products_code_not_blank');
    expectDbRejects(fn () => DB::table('products')->insert([
        'code' => $product->code, 'name' => 'X', 'presentation' => 'X', 'unit' => 'tableta',
    ]), 'products_code_unique');
    expectDbRejects(fn () => DB::table('lots')->insert([
        'product_id' => $product->id, 'lot_number' => ' ', 'expires_at' => '2030-01-01',
    ]), 'lots_lot_number_not_blank');
});

it('el mismo número de lote sí puede repetirse en productos distintos', function () {
    $lot = Lot::factory()->create();

    DB::table('lots')->insert([
        'product_id' => Product::factory()->create()->id,
        'lot_number' => $lot->lot_number,
        'expires_at' => '2030-01-01',
    ]);

    expect(Lot::query()->where('lot_number', $lot->lot_number)->count())->toBe(2);
});

it('RN-03 límite: stock en 0 es válido (descontar exactamente lo disponible)', function () {
    $stock = Stock::factory()->create(['quantity' => 3]);

    DB::update('UPDATE stocks SET quantity = quantity - 3 WHERE id = ?', [$stock->id]);

    expect($stock->fresh()?->quantity)->toBe(0);
});

it('un mínimo de stock es único por bodega + producto', function () {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $row = ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'min_quantity' => 5];
    DB::table('stock_minimums')->insert($row);

    expectDbRejects(fn () => DB::table('stock_minimums')->insert($row), 'stock_minimums_warehouse_id_product_id_unique');
});

it('RN-06 límite: balance_after = 0 es válido (salida de la última unidad)', function () {
    $stock = Stock::factory()->create(['quantity' => 0]);

    DB::table('kardex_movements')->insert(kardexRow($stock, [
        'type' => 'SALIDA_DISPENSACION', 'quantity' => 1, 'direction' => -1, 'balance_after' => 0,
    ]));

    expect(KardexMovement::query()->value('balance_after'))->toBe(0);
});

it('RN-06: dirección por tipo, casos que faltaban', function (array $overrides, ?string $constraint) {
    $stock = Stock::factory()->create();

    if ($constraint === null) {
        DB::table('kardex_movements')->insert(kardexRow($stock, $overrides));
        expect(KardexMovement::query()->count())->toBe(1);

        return;
    }

    expectDbRejects(fn () => DB::table('kardex_movements')->insert(kardexRow($stock, $overrides)), $constraint);
})->with([
    'ENTRADA que resta' => [['type' => 'ENTRADA', 'direction' => -1], 'kardex_movements_direction_matches_type'],
    'SALIDA_TRASLADO que suma' => [['type' => 'SALIDA_TRASLADO', 'direction' => 1], 'kardex_movements_direction_matches_type'],
    'AJUSTE positivo con motivo (válido)' => [['type' => 'AJUSTE', 'direction' => 1, 'reason' => 'Sobrante en conteo'], null],
    'SALIDA_TRASLADO que resta (válido)' => [['type' => 'SALIDA_TRASLADO', 'direction' => -1], null],
]);

it('rechaza un movimiento de kardex con product_id distinto al del lote', function () {
    $stock = Stock::factory()->create();

    expectDbRejects(
        fn () => DB::table('kardex_movements')->insert(kardexRow($stock, ['product_id' => Product::factory()->create()->id])),
        'kardex_movements_lot_id_product_id_foreign',
    );
});

it('no se puede borrar una existencia que tiene movimientos de kardex', function () {
    $stock = Stock::factory()->create(['quantity' => 5]);
    DB::table('kardex_movements')->insert(kardexRow($stock));

    expectDbRejects(fn () => DB::table('stocks')->where('id', $stock->id)->delete(), 'kardex_movements_warehouse_id_lot_id_foreign');
});
