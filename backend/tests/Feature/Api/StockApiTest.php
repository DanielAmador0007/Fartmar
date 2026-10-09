<?php

/*
| GET /api/v1/stocks: consulta de inventario por bodega, producto y lote.
*/

use App\Models\Lot;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::factory()->create();
    $this->late = Lot::factory()->expiresInDays(300)->create();
    $this->early = Lot::factory()->for($this->late->product)->expiresInDays(20)->create();
    $this->expired = Lot::factory()->for($this->late->product)->expired()->create();
    $this->empty = Lot::factory()->for($this->late->product)->expiresInDays(50)->create();

    foreach ([[$this->late, 7], [$this->early, 3], [$this->expired, 4], [$this->empty, 0]] as [$lot, $qty]) {
        Stock::factory()->create(['warehouse_id' => $this->warehouse->id, 'lot_id' => $lot->id, 'quantity' => $qty]);
    }
    Stock::factory()->create(['quantity' => 99]); // otra bodega y otro producto
});

it('lista existencias filtradas por bodega en orden FEFO, marcando vencidos y ocultando vacías', function () {
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->getJson("/api/v1/stocks?warehouse_id={$this->warehouse->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.lot.id', $this->expired->id)
        ->assertJsonPath('data.0.lot.is_expired', true)
        ->assertJsonPath('data.1.lot.id', $this->early->id)
        ->assertJsonPath('data.1.lot.is_expired', false)
        ->assertJsonPath('data.2.lot.id', $this->late->id)
        ->assertJsonPath('data.2.quantity', 7)
        ->assertJsonPath('meta.total', 3);
});

it('filtra por producto y lote, e incluye vacías si se pide', function () {
    Sanctum::actingAs(User::factory()->regente()->create());

    $this->getJson("/api/v1/stocks?product_id={$this->late->product_id}&include_empty=1")
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $this->getJson("/api/v1/stocks?lot_id={$this->early->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.quantity', 3);
});

it('el auditor puede consultar; el médico no', function () {
    Sanctum::actingAs(User::factory()->auditor()->create());
    $this->getJson('/api/v1/stocks')->assertOk();

    Sanctum::actingAs(User::factory()->medico()->create());
    $this->getJson('/api/v1/stocks')->assertForbidden()->assertJsonPath('error.code', 'NO_AUTORIZADO');
});

it('valida los filtros', function () {
    Sanctum::actingAs(User::factory()->auxiliar()->create());

    $this->getJson('/api/v1/stocks?per_page=1000')->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
});
