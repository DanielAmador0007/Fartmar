<?php

/*
| L2 (revisión de la Fase 3): la bodega se serializa igual en existencias,
| kardex, alertas y traslados (WarehouseSummaryResource compartido).
*/

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

it('L2: la bodega es {id, code, name} en stocks, kardex, alertas y traslados', function () {
    // Lote que vence en 10 días (alerta de vencimiento) y mínimo de 50 (bajo stock).
    $s = TransferScenario::make([[10, 5]]);
    DB::table('stock_minimums')->insert(['warehouse_id' => $s->origin->id, 'product_id' => $s->product->id, 'min_quantity' => 50]);
    $transfer = $s->requested(1);
    $expected = ['id' => $s->origin->id, 'code' => $s->origin->code, 'name' => $s->origin->name];

    Sanctum::actingAs(User::factory()->auditor()->create());

    expect(test()->getJson("/api/v1/stocks?warehouse_id={$s->origin->id}")->assertOk()->json('data.0.warehouse'))->toBe($expected)
        ->and(test()->getJson("/api/v1/kardex?warehouse_id={$s->origin->id}")->assertOk()->json('data.0.warehouse'))->toBe($expected)
        ->and(test()->getJson("/api/v1/alerts?warehouse_id={$s->origin->id}")->assertOk()->json('data.expiring_lots.0.warehouse'))->toBe($expected)
        ->and(test()->getJson("/api/v1/alerts?warehouse_id={$s->origin->id}")->json('data.low_stock.0.warehouse'))->toBe($expected)
        ->and(test()->getJson("/api/v1/transfers/{$transfer->id}")->assertOk()->json('data.origin_warehouse'))->toBe($expected)
        ->and(test()->getJson('/api/v1/transfers')->json('data.0.origin_warehouse'))->toBe($expected);
});
