<?php

/*
| StockService + KardexService (RN-01, RN-03, RN-06): cada cambio de stock
| deja un movimiento con quantity > 0, direction ±1 y balance_after correcto.
*/

use App\Domain\Exceptions\ExpiredLotException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Inventory\BusinessDate;
use App\Domain\Inventory\StockService;
use App\Enums\KardexType;
use App\Models\KardexMovement;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(StockService::class);
    $this->user = User::factory()->regente()->create();
    $this->warehouse = Warehouse::factory()->create();
    $this->lot = Lot::factory()->create();
});

it('RN-06: increase crea la existencia y registra ENTRADA con balance_after', function () {
    $movement = DB::transaction(fn () => $this->service->increase(
        $this->warehouse->id, $this->lot, 25, KardexType::Entrada, $this->user, reason: 'Compra',
    ));

    $stock = Stock::query()->where('warehouse_id', $this->warehouse->id)->where('lot_id', $this->lot->id)->sole();

    expect($stock->quantity)->toBe(25)
        ->and($stock->product_id)->toBe($this->lot->product_id)
        ->and($movement->type)->toBe(KardexType::Entrada)
        ->and($movement->quantity)->toBe(25)
        ->and($movement->direction)->toBe(1)
        ->and($movement->balance_after)->toBe(25)
        ->and($movement->user_id)->toBe($this->user->id);
});

it('RN-06: una secuencia de entradas y salidas deja balance_after = saldo anterior ± cantidad', function () {
    DB::transaction(function () {
        $this->service->increase($this->warehouse->id, $this->lot, 10, KardexType::Entrada, $this->user);
        $this->service->decrease($this->warehouse->id, $this->lot->id, 3, KardexType::SalidaDispensacion, $this->user);
        $this->service->increase($this->warehouse->id, $this->lot, 5, KardexType::EntradaTraslado, $this->user);
        $this->service->decrease($this->warehouse->id, $this->lot->id, 12, KardexType::SalidaTraslado, $this->user);
    });

    $movements = KardexMovement::query()->where('lot_id', $this->lot->id)->orderBy('id')->get();

    expect($movements->map(fn (KardexMovement $m) => [$m->type->value, $m->quantity, $m->direction, $m->balance_after])->all())
        ->toBe([
            ['ENTRADA', 10, 1, 10],
            ['SALIDA_DISPENSACION', 3, -1, 7],
            ['ENTRADA_TRASLADO', 5, 1, 12],
            ['SALIDA_TRASLADO', 12, -1, 0],
        ]);

    // Saldo = suma de movimientos firmados = último balance_after.
    $signedSum = $movements->sum(fn (KardexMovement $m) => $m->quantity * $m->direction);
    expect($signedSum)->toBe(0)
        ->and(Stock::query()->where('lot_id', $this->lot->id)->value('quantity'))->toBe(0);
});

it('RN-03: decrease no deja stock negativo ni escribe kardex si no alcanza', function () {
    Stock::factory()->create(['warehouse_id' => $this->warehouse->id, 'lot_id' => $this->lot->id, 'quantity' => 2]);

    try {
        DB::transaction(fn () => $this->service->decrease(
            $this->warehouse->id, $this->lot->id, 3, KardexType::SalidaDispensacion, $this->user,
        ));
        $this->fail('Debía lanzar InsufficientStockException');
    } catch (InsufficientStockException $e) {
        expect($e->details())->toMatchArray(['requested' => 3, 'available' => 2]);
    }

    expect(Stock::query()->where('lot_id', $this->lot->id)->value('quantity'))->toBe(2)
        ->and(KardexMovement::query()->count())->toBe(0);
});

it('RN-03: decrease de un lote sin existencia en la bodega lanza STOCK_INSUFICIENTE', function () {
    DB::transaction(fn () => $this->service->decrease(
        $this->warehouse->id, $this->lot->id, 1, KardexType::SalidaDispensacion, $this->user,
    ));
})->throws(InsufficientStockException::class);

it('RN-01: no permite sacar por dispensación o traslado un lote vencido', function (KardexType $type) {
    $expired = Lot::factory()->expired()->create();
    Stock::factory()->create(['warehouse_id' => $this->warehouse->id, 'lot_id' => $expired->id, 'quantity' => 5]);

    expect(fn () => DB::transaction(fn () => $this->service->decrease(
        $this->warehouse->id, $expired->id, 1, $type, $this->user,
    )))->toThrow(ExpiredLotException::class);

    expect(Stock::query()->where('lot_id', $expired->id)->value('quantity'))->toBe(5);
})->with([KardexType::SalidaDispensacion, KardexType::SalidaTraslado]);

it('un AJUSTE negativo sí puede retirar un lote vencido (baja por vencimiento) con motivo', function () {
    $expired = Lot::factory()->expired()->create();
    Stock::factory()->create(['warehouse_id' => $this->warehouse->id, 'lot_id' => $expired->id, 'quantity' => 5]);

    $movement = DB::transaction(fn () => $this->service->decrease(
        $this->warehouse->id, $expired->id, 5, KardexType::Ajuste, $this->user, reason: 'Baja por vencimiento',
    ));

    expect($movement->balance_after)->toBe(0)->and($movement->direction)->toBe(-1);
});

it('rechaza movimientos con cantidad no positiva', function () {
    expect(fn () => $this->service->decrease($this->warehouse->id, $this->lot->id, 0, KardexType::SalidaDispensacion, $this->user))
        ->toThrow(InvalidArgumentException::class);
});

it('lockFefoCandidates devuelve solo lotes no vencidos con existencias, en orden FEFO', function () {
    $product = Product::factory()->create();
    $late = Lot::factory()->for($product)->expiresInDays(200)->create();
    $early = Lot::factory()->for($product)->expiresInDays(20)->create();
    $expired = Lot::factory()->for($product)->expired()->create();
    $empty = Lot::factory()->for($product)->expiresInDays(5)->create();
    $otherWarehouse = Lot::factory()->for($product)->expiresInDays(1)->create();

    foreach ([[$late, 10], [$early, 4], [$expired, 50], [$empty, 0]] as [$lot, $qty]) {
        Stock::factory()->create(['warehouse_id' => $this->warehouse->id, 'lot_id' => $lot->id, 'quantity' => $qty]);
    }
    Stock::factory()->create(['lot_id' => $otherWarehouse->id, 'quantity' => 99]);

    $candidates = DB::transaction(fn () => $this->service->lockFefoCandidates($this->warehouse->id, $product->id, BusinessDate::today()));

    expect(array_map(fn ($c) => [$c->lotId, $c->quantity], $candidates))
        ->toBe([[$early->id, 4], [$late->id, 10]]);
});
