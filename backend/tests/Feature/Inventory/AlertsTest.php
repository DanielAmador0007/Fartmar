<?php

/*
| Alertas de inventario (RN-11): vencimientos y stock mínimo, por servicio y API.
*/

use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\AlertService;
use App\Models\Lot;
use App\Models\Product;
use App\Models\StockMinimum;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

it('RN-11: alerta lotes con existencias que vencen en <= 90 días, incluidos los vencidos, ordenados por urgencia', function () {
    $s = TransferScenario::make([
        [-5, 3],   // vencido con unidades
        [0, 2],    // vence hoy (aún usable)
        [90, 4],   // justo en el límite
        [91, 6],   // fuera de la ventana
    ]);
    [$expired, $today, $limit] = $s->lots;
    // Lote dentro de la ventana pero SIN existencias: no alerta.
    $empty = $s->addLot($s->product, 10, 1);
    app(AdjustmentService::class)->adjust($s->regente, $s->origin->id, $empty->id, -1, 'Conteo físico');

    $alerts = app(AlertService::class)->expiringLots();

    expect(array_map(fn ($a) => [$a->stock->lot_id, $a->daysToExpire, $a->isExpired()], $alerts))->toBe([
        [$expired->id, -5, true],
        [$today->id, 0, false],
        [$limit->id, 90, false],
    ]);
});

it('RN-11: el umbral de días es configurable (ALERT_EXPIRY_DAYS)', function () {
    config(['fartmar.alert_expiry_days' => 30]);
    $s = TransferScenario::make([[20, 1], [40, 1]]);

    $alerts = app(AlertService::class)->expiringLots();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->stock->lot_id)->toBe($s->lots[0]->id);
});

it('RN-11: stock mínimo cuenta solo lo disponible (no vencido) e incluye productos sin existencias', function () {
    $s = TransferScenario::make([[30, 4], [-1, 100]]);   // disponible 4 (el vencido no cuenta)
    $ok = Product::factory()->create();
    $s->addLot($ok, 30, 10);
    $none = Product::factory()->create();

    StockMinimum::query()->create(['warehouse_id' => $s->origin->id, 'product_id' => $s->product->id, 'min_quantity' => 5]);
    StockMinimum::query()->create(['warehouse_id' => $s->origin->id, 'product_id' => $ok->id, 'min_quantity' => 10]);   // igual al mínimo: no alerta
    StockMinimum::query()->create(['warehouse_id' => $s->origin->id, 'product_id' => $none->id, 'min_quantity' => 1]);
    // El mínimo es por bodega: en el destino no hay nada del producto.
    StockMinimum::query()->create(['warehouse_id' => $s->destination->id, 'product_id' => $s->product->id, 'min_quantity' => 2]);

    $alerts = app(AlertService::class)->lowStock();

    expect(array_map(fn ($a) => [$a->warehouse->id, $a->product->id, $a->available, $a->shortfall()], $alerts))->toBe([
        [$s->origin->id, $s->product->id, 4, 1],
        [$s->origin->id, $none->id, 0, 1],
        [$s->destination->id, $s->product->id, 0, 2],
    ]);

    expect(app(AlertService::class)->lowStock($s->destination->id))->toHaveCount(1);
});

it('GET alerts devuelve ambas listas, filtra por bodega y la ve el auditor', function () {
    $s = TransferScenario::make([[10, 3]]);
    $s->addLot($s->product, 10, 2, $s->destination);
    StockMinimum::query()->create(['warehouse_id' => $s->origin->id, 'product_id' => $s->product->id, 'min_quantity' => 5]);

    Sanctum::actingAs(User::factory()->auditor()->create());

    test()->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.expiry_days', 90)
        ->assertJsonCount(2, 'data.expiring_lots')
        ->assertJsonCount(1, 'data.low_stock')
        ->assertJsonPath('data.low_stock.0.min_quantity', 5)
        ->assertJsonPath('data.low_stock.0.available', 3)
        ->assertJsonPath('data.low_stock.0.shortfall', 2);

    test()->getJson("/api/v1/alerts?warehouse_id={$s->destination->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.expiring_lots')
        ->assertJsonPath('data.expiring_lots.0.warehouse.id', $s->destination->id)
        ->assertJsonPath('data.expiring_lots.0.days_to_expire', 10)
        ->assertJsonPath('data.expiring_lots.0.is_expired', false)
        ->assertJsonCount(0, 'data.low_stock');
});

it('L3: cada lista de alertas se recorta a limit (lo más urgente primero) e informa el total', function () {
    // 4 lotes por vencer (5, 10, 20, 30 días) y 3 productos bajo mínimo.
    $s = TransferScenario::make([[30, 1], [5, 1], [20, 1], [10, 1]]);
    foreach ([$s->product, Product::factory()->create(), Product::factory()->create()] as $product) {
        StockMinimum::query()->create(['warehouse_id' => $s->origin->id, 'product_id' => $product->id, 'min_quantity' => 50]);
    }

    $service = app(AlertService::class);
    expect(array_map(fn ($a) => $a->daysToExpire, $service->expiringLots(null, 2)))->toBe([5, 10])
        ->and($service->countExpiringLots())->toBe(4)
        ->and($service->lowStock(null, 2))->toHaveCount(2)
        ->and($service->countLowStock())->toBe(3)
        ->and($service->countLowStock($s->destination->id))->toBe(0);

    Sanctum::actingAs(User::factory()->auditor()->create());
    test()->getJson('/api/v1/alerts?limit=2')
        ->assertOk()
        ->assertJsonPath('data.limit', 2)
        ->assertJsonCount(2, 'data.expiring_lots')
        ->assertJsonPath('data.expiring_lots_total', 4)
        ->assertJsonPath('data.expiring_lots.0.days_to_expire', 5)
        ->assertJsonCount(2, 'data.low_stock')
        ->assertJsonPath('data.low_stock_total', 3);

    // Por defecto 100.
    test()->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.limit', 100)
        ->assertJsonCount(4, 'data.expiring_lots');
});

it('L3: limit fuera de rango responde 422', function (string $limit) {
    Sanctum::actingAs(User::factory()->auditor()->create());

    test()->getJson("/api/v1/alerts?limit={$limit}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDACION');
})->with(['0', '501', 'muchos']);

it('RN-11: borde con ALERT_EXPIRY_DAYS configurado (30): el día 30 alerta, el 31 no', function () {
    config(['fartmar.alert_expiry_days' => 30]);
    $s = TransferScenario::make([[31, 1], [30, 1]]);

    $alerts = app(AlertService::class)->expiringLots();

    expect(array_map(fn ($a) => [$a->stock->lot_id, $a->daysToExpire], $alerts))->toBe([[$s->lots[1]->id, 30]])
        ->and(app(AlertService::class)->countExpiringLots())->toBe(1);
});

it('RN-11 con fechas relativas: al pasar los días el lote entra a la ventana, vence y deja de cubrir el mínimo', function () {
    // Un solo lote de 5 u. que vence en 91 días; mínimo de 5 (igual: no alerta).
    $s = TransferScenario::make([[91, 5]]);
    StockMinimum::query()->create(['warehouse_id' => $s->origin->id, 'product_id' => $s->product->id, 'min_quantity' => 5]);
    // S-51: un mínimo de 0 nunca alerta, ni sin existencias ni con todo vencido.
    StockMinimum::query()->create(['warehouse_id' => $s->origin->id, 'product_id' => Product::factory()->create()->id, 'min_quantity' => 0]);
    $service = app(AlertService::class);
    $lot = $s->lots[0];

    // Hoy: día 91, fuera de la ventana; stock == mínimo.
    expect($service->expiringLots())->toBe([])
        ->and($service->lowStock())->toBe([]);

    // Mañana: día 90, entra.
    $this->travel(1)->days();
    expect(array_map(fn ($a) => [$a->stock->lot_id, $a->daysToExpire, $a->isExpired()], $service->expiringLots()))
        ->toBe([[$lot->id, 90, false]])
        ->and($service->lowStock())->toBe([]);

    // Día del vencimiento: vence HOY, sigue siendo usable (cuenta para el mínimo).
    $this->travel(90)->days();
    expect(array_map(fn ($a) => [$a->daysToExpire, $a->isExpired()], $service->expiringLots()))->toBe([[0, false]])
        ->and($service->lowStock())->toBe([]);

    // Un día después: vencido (días negativos) y ya no cubre el mínimo.
    $this->travel(1)->days();
    expect(array_map(fn ($a) => [$a->daysToExpire, $a->isExpired()], $service->expiringLots()))->toBe([[-1, true]])
        ->and(array_map(fn ($a) => [$a->product->id, $a->available, $a->shortfall()], $service->lowStock()))
        ->toBe([[$s->product->id, 0, 5]]);
});

it('RN-11: "hoy" de las alertas es el día de Bogotá, no el de UTC (S-13)', function () {
    // 2026-10-12 03:00 UTC = 2026-10-11 22:00 en Bogotá: un lote que vence
    // el 11 aún vence HOY (no vencido) aunque en UTC ya sea 12.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:00:00', 'UTC'));
    $s = TransferScenario::make([]);
    $lot = Lot::factory()->for($s->product)->create(['expires_at' => '2026-10-11']);
    app(AdjustmentService::class)->adjust($s->regente, $s->origin->id, $lot->id, 3, 'Conteo físico');

    expect(array_map(fn ($a) => [$a->daysToExpire, $a->isExpired()], app(AlertService::class)->expiringLots()))->toBe([[0, false]]);

    // 05:00 UTC = medianoche en Bogotá: ahora sí está vencido.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 05:00:00', 'UTC'));
    expect(array_map(fn ($a) => [$a->daysToExpire, $a->isExpired()], app(AlertService::class)->expiringLots()))->toBe([[-1, true]]);
});

it('lotsExpiringWithin: solo lotes no vencidos con unidades dentro de la ventana, filtrables por bodega y producto', function () {
    $s = TransferScenario::make([
        [-1, 3],   // vencido: no entra
        [0, 2],    // vence hoy: entra
        [60, 4],   // justo en el límite de 60: entra
        [61, 6],   // fuera
    ]);
    [, $today, $limit] = $s->lots;
    $other = Product::factory()->create();
    $otherLot = $s->addLot($other, 10, 5);
    $inDestination = $s->addLot($s->product, 5, 1, $s->destination);

    $service = app(AlertService::class);
    $ids = fn (array $alerts) => array_map(fn ($a) => $a->stock->lot_id, $alerts);

    expect($ids($service->lotsExpiringWithin(60, $s->origin->id, $s->product->id)))->toBe([$today->id, $limit->id])
        ->and($ids($service->lotsExpiringWithin(60, $s->origin->id)))->toBe([$today->id, $otherLot->id, $limit->id])
        ->and($ids($service->lotsExpiringWithin(60, null, $s->product->id)))->toBe([$today->id, $inDestination->id, $limit->id])
        ->and($ids($service->lotsExpiringWithin(1, $s->origin->id, $s->product->id)))->toBe([$today->id]);
});

it('expiredLots: solo lotes vencidos con unidades', function () {
    $s = TransferScenario::make([[-10, 3], [-1, 2], [0, 4]]);
    [$old, $yesterday] = $s->lots;
    $emptyExpired = $s->addLot($s->product, -3, 1);
    app(AdjustmentService::class)->adjust($s->regente, $s->origin->id, $emptyExpired->id, -1, 'Baja por vencimiento');

    $alerts = app(AlertService::class)->expiredLots($s->origin->id, $s->product->id);

    expect(array_map(fn ($a) => [$a->stock->lot_id, $a->daysToExpire, $a->isExpired()], $alerts))->toBe([
        [$old->id, -10, true],
        [$yesterday->id, -1, true],
    ]);
});
