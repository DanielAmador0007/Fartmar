<?php

/*
| Errores de PostgreSQL con significado de negocio (revisión Fase 2, M1).
|
| Los CHECK de la BD son la última línea de defensa: si algún camino se
| saltara la validación en PHP, la BD rechaza la escritura. La API debe
| responder entonces con el código de negocio y el JSON uniforme, no con
| 500 ERROR_INTERNO. Se identifican por SQLSTATE + nombre del constraint
| leído del mensaje del servidor (App\Database\PostgresError).
*/

use App\Models\Dispensation;
use App\Models\KardexMovement;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DispensingScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Con APP_DEBUG=false cualquier error NO reconocido sería 500 ERROR_INTERNO.
    config(['app.debug' => false]);
});

/**
 * Excepción como la que lanza PDO para un error de PostgreSQL.
 */
function pgQueryException(string $sqlState, string $serverMessage, string $sql = 'update "stocks" set "quantity" = "quantity" + ?'): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$serverMessage}");
    $pdo->errorInfo = [$sqlState, 7, $serverMessage];

    return new QueryException('pgsql', $sql, [], $pdo);
}

it('CHECK stocks_quantity_non_negative por el endpoint real responde 409 STOCK_INSUFICIENTE y no deja rastro', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);

    // Simula un camino que se saltó el bloqueo: justo después de que
    // StockService lee (y bloquea) la existencia con saldo 10, la existencia
    // queda en 0. El código en PHP cree que hay 10; el UPDATE relativo deja
    // -1 y el CHECK de la BD lo rechaza.
    $done = false;
    DB::listen(function (QueryExecuted $q) use (&$done): void {
        if (! $done && str_starts_with($q->sql, 'select * from "stocks" where "warehouse_id"') && str_contains($q->sql, 'for update')) {
            $done = true;
            DB::update('update stocks set quantity = 0');
        }
    });

    $this->postJson('/api/v1/dispensations', $s->payload(1), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)
        ->assertExactJson(['error' => [
            'code' => 'STOCK_INSUFICIENTE',
            'message' => 'No hay existencias suficientes: otra operación tomó las unidades al mismo tiempo. Actualice el inventario e intente de nuevo.',
            'details' => [],
        ]]);

    expect($done)->toBeTrue()
        ->and(Dispensation::query()->count())->toBe(0)
        ->and(KardexMovement::query()->count())->toBe(0)
        ->and($s->totalStock())->toBe(10);
});

it('lo dispensado se suma en la BD (UPDATE relativo): si la línea cambió sin bloqueo, el CHECK frena el exceso', function () {
    $s = DispensingScenario::make([[30, 10]], prescribed: 5);
    Sanctum::actingAs($s->auxiliar);

    // Simula un camino sin bloqueo: después de que el servicio lee y valida
    // la línea (quantity_dispensed = 0 en memoria), otra entrega deja la
    // línea completa (5 de 5). Con "quantity_dispensed = 0 + 1" escrito desde
    // PHP se perderían esas 5 unidades en silencio; con el UPDATE relativo la
    // BD calcula 6 > 5 y el CHECK lo rechaza.
    $done = false;
    DB::listen(function (QueryExecuted $q) use (&$done): void {
        if (! $done && str_starts_with($q->sql, 'select * from "prescription_items"') && str_contains($q->sql, 'for update')) {
            $done = true;
            DB::update('update prescription_items set quantity_dispensed = quantity_prescribed');
        }
    });

    $this->postJson('/api/v1/dispensations', $s->payload(1), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PRESCRIPCION_EXCEDIDA');

    expect($done)->toBeTrue()
        ->and(Dispensation::query()->count())->toBe(0)
        ->and(KardexMovement::query()->count())->toBe(0)
        ->and($s->totalStock())->toBe(10)
        ->and($s->item->fresh()?->quantity_dispensed)->toBe(0);
});

it('CHECK prescription_items_dispensed_le_prescribed responde 422 PRESCRIPCION_EXCEDIDA', function () {
    $s = DispensingScenario::make(prescribed: 5);
    Route::middleware('api')->post('/api/v1/_prueba/excede', fn () => DB::transaction(
        fn () => DB::update('update prescription_items set quantity_dispensed = quantity_prescribed + 1 where id = ?', [$s->item->id]),
    ));

    $this->postJson('/api/v1/_prueba/excede')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PRESCRIPCION_EXCEDIDA')
        ->assertJsonPath('error.details', []);

    expect($s->item->fresh()?->quantity_dispensed)->toBe(0);
});

it('identifica el constraint por el mensaje del servidor, no por el SQL de la consulta', function () {
    // El SQL menciona el constraint de stocks (comentario), pero la BD
    // rechaza por el de la prescripción: debe salir PRESCRIPCION_EXCEDIDA.
    $s = DispensingScenario::make(prescribed: 5);
    Route::middleware('api')->post('/api/v1/_prueba/engano', fn () => DB::transaction(
        fn () => DB::update('update prescription_items set quantity_dispensed = 99 where id = ? /* stocks_quantity_non_negative */', [$s->item->id]),
    ));

    $this->postJson('/api/v1/_prueba/engano')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PRESCRIPCION_EXCEDIDA');
});

it('un CHECK sin significado de negocio conocido sigue siendo 500 ERROR_INTERNO sin detalles', function () {
    $s = DispensingScenario::make();
    Route::middleware('api')->post('/api/v1/_prueba/otro-check', fn () => DB::transaction(
        fn () => DB::update('update prescription_items set quantity_prescribed = 0 where id = ?', [$s->item->id]),
    ));

    $body = $this->postJson('/api/v1/_prueba/otro-check')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'ERROR_INTERNO')
        ->getContent();

    expect((string) $body)->not->toContain('SQLSTATE')->not->toContain('prescription_items');
});

it('deadlock o fallo de serialización tras agotar reintentos responde 409 CONFLICTO_CONCURRENCIA', function (string $sqlState, string $serverMessage) {
    Route::middleware('api')->post('/api/v1/_prueba/carrera', fn () => throw pgQueryException($sqlState, $serverMessage));

    $this->postJson('/api/v1/_prueba/carrera')
        ->assertStatus(409)
        ->assertExactJson(['error' => [
            'code' => 'CONFLICTO_CONCURRENCIA',
            'message' => 'Otra operación estaba modificando los mismos datos. Espere un momento y reintente; no se aplicó ningún cambio.',
            'details' => [],
        ]]);
})->with([
    'deadlock (40P01)' => ['40P01', 'ERROR:  deadlock detected'],
    'serialización (40001)' => ['40001', 'ERROR:  could not serialize access due to concurrent update'],
]);

it('el mapeo de errores de la BD aplica también con APP_DEBUG=true', function () {
    config(['app.debug' => true]);
    Route::middleware('api')->post('/api/v1/_prueba/check-debug', fn () => throw pgQueryException(
        '23514',
        "ERROR:  new row for relation \"stocks\" violates check constraint \"stocks_quantity_non_negative\"\nDETAIL:  Failing row contains (1, -1).",
    ));

    $this->postJson('/api/v1/_prueba/check-debug')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'STOCK_INSUFICIENTE');
});
