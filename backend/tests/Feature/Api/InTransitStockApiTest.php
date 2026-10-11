<?php

/*
| M1 (revisión de la Fase 3): stock en tránsito consultable.
| GET /api/v1/stocks/in-transit: lo despachado y aún no recibido.
*/

use App\Domain\Transfers\TransferService;
use App\Models\Product;
use App\Models\TransferItemLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

it('M1: lista por lote lo despachado y no recibido, con origen, destino y traslado', function () {
    // FEFO: 2 del lote que vence antes y 3 del siguiente.
    $s = TransferScenario::make([[10, 2], [90, 10]]);
    [$early, $late] = $s->lots;
    $transfer = $s->dispatched(5);

    Sanctum::actingAs($s->auxiliar);
    $rows = test()->getJson('/api/v1/stocks/in-transit')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.transfer_id', $transfer->id)
        ->assertJsonPath('data.0.origin_warehouse.id', $s->origin->id)
        ->assertJsonPath('data.0.destination_warehouse.id', $s->destination->id)
        ->assertJsonPath('data.0.product.id', $s->product->id)
        ->assertJsonPath('data.0.lot.id', $early->id)
        ->assertJsonPath('data.0.lot.is_expired', false)
        ->assertJsonPath('data.0.quantity', 2)
        ->assertJsonPath('data.1.lot.id', $late->id)
        ->assertJsonPath('data.1.quantity', 3)
        ->json('data');

    // Conservación: origen + en tránsito = lo que había (12); destino aún 0.
    expect($s->totalAt($s->origin) + array_sum(array_column($rows, 'quantity')))->toBe(12)
        ->and($s->totalAt($s->destination))->toBe(0);
});

it('M1: no incluye traslados sin despachar ni ya recibidos (ni con recepción parcial)', function () {
    $s = TransferScenario::make([[30, 100]]);
    $s->approved(4); // APROBADO: aún no sale stock.
    $received = $s->dispatched(3);
    $partial = $s->dispatched(2);
    $pending = $s->dispatched(1);

    $service = app(TransferService::class);
    $lotOf = fn ($t) => TransferItemLot::query()->whereIn('transfer_item_id', $t->items()->select('id'))->sole();
    $service->receive($received, $s->auxiliar, [$lotOf($received)->id => 3]);
    $service->receive($partial, $s->auxiliar, [$lotOf($partial)->id => 1]); // lo faltante es discrepancia, no tránsito.

    Sanctum::actingAs(User::factory()->auditor()->create());
    test()->getJson('/api/v1/stocks/in-transit')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.transfer_id', $pending->id)
        ->assertJsonPath('data.0.quantity', 1);
});

it('M1: filtra por bodega (origen o destino), origen, destino, producto y lote', function () {
    $s = TransferScenario::make([[30, 10]]);
    $other = Product::factory()->create();
    $otherLot = $s->addLot($other, 60, 10);
    $service = app(TransferService::class);
    $transfer = $service->create($s->auxiliar, $s->data(products: [$s->product->id => 2, $other->id => 4]));
    $service->dispatch($service->approve($service->submit($transfer, $s->auxiliar), $s->regente), $s->auxiliar);

    Sanctum::actingAs($s->regente);
    $count = fn (string $query) => count(test()->getJson("/api/v1/stocks/in-transit?{$query}")->assertOk()->json('data'));

    expect($count(''))->toBe(2)
        ->and($count("warehouse_id={$s->origin->id}"))->toBe(2)
        ->and($count("warehouse_id={$s->destination->id}"))->toBe(2)
        ->and($count("origin_warehouse_id={$s->destination->id}"))->toBe(0)
        ->and($count("destination_warehouse_id={$s->destination->id}"))->toBe(2)
        ->and($count("product_id={$other->id}"))->toBe(1)
        ->and($count("lot_id={$otherLot->id}"))->toBe(1)
        ->and($count('lot_id=999999'))->toBe(0);

    test()->getJson('/api/v1/stocks/in-transit?per_page=500')->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
});

it('M1: es solo lectura para farmacia y auditor; un médico recibe 403', function () {
    Sanctum::actingAs(User::factory()->medico()->create());

    test()->getJson('/api/v1/stocks/in-transit')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'NO_AUTORIZADO');
});
