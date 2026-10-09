<?php

/*
| Ajustes de inventario (RN-03, RN-06, S-35).
*/

use App\Domain\Exceptions\AdjustmentNotValidException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Domain\Inventory\AdjustmentService;
use App\Enums\KardexType;
use App\Models\AuditLog;
use App\Models\KardexMovement;
use App\Models\Lot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConcurrentDispenser;
use Tests\Support\TransferScenario;

uses(RefreshDatabase::class);

function adjustments(): AdjustmentService
{
    return app(AdjustmentService::class);
}

it('ajuste positivo suma, escribe AJUSTE +1 con motivo y balance_after, y audita sin el motivo', function () {
    $s = TransferScenario::make([[30, 10]]);

    $movement = adjustments()->adjust($s->regente, $s->origin->id, $s->lots[0]->id, 3, '  Conteo físico mensual  ');

    expect($movement->type)->toBe(KardexType::Ajuste)
        ->and($movement->direction)->toBe(1)
        ->and($movement->quantity)->toBe(3)
        ->and($movement->balance_after)->toBe(13)
        ->and($movement->reason)->toBe('Conteo físico mensual')
        ->and($movement->user_id)->toBe($s->regente->id)
        ->and($s->stockAt($s->origin, $s->lots[0]))->toBe(13)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);

    $log = AuditLog::query()->where('action', 'inventory.adjusted')->sole();
    expect($log->metadata)->toMatchArray(['kardex_movement_id' => $movement->id, 'quantity' => 3, 'balance_after' => 13])
        ->and(json_encode($log->metadata))->not->toContain('Conteo');
});

it('ajuste negativo resta y escribe AJUSTE -1', function () {
    $s = TransferScenario::make([[30, 10]]);

    $movement = adjustments()->adjust($s->regente, $s->origin->id, $s->lots[0]->id, -4, 'Unidades averiadas');

    expect($movement->direction)->toBe(-1)
        ->and($movement->quantity)->toBe(4)
        ->and($movement->balance_after)->toBe(6)
        ->and($s->stockAt($s->origin, $s->lots[0]))->toBe(6);
});

it('S-35: un ajuste negativo SÍ puede dar de baja un lote vencido', function () {
    $s = TransferScenario::make([[-10, 7]]);

    adjustments()->adjust($s->regente, $s->origin->id, $s->lots[0]->id, -7, 'Baja por vencimiento');

    expect($s->stockAt($s->origin, $s->lots[0]))->toBe(0);
});

it('ajuste positivo de un lote que la bodega no tenía crea la existencia', function () {
    $s = TransferScenario::make([[30, 10]]);
    $lot = Lot::factory()->for($s->product)->create();

    adjustments()->adjust($s->regente, $s->destination->id, $lot->id, 2, 'Sobrante encontrado en conteo');

    expect($s->stockAt($s->destination, $lot))->toBe(2)
        ->and(ConcurrentDispenser::kardexInconsistencies())->toBe([]);
});

it('RN-03: un ajuste negativo nunca deja stock negativo', function () {
    $s = TransferScenario::make([[30, 3]]);

    expect(fn () => adjustments()->adjust($s->regente, $s->origin->id, $s->lots[0]->id, -4, 'Conteo'))
        ->toThrow(InsufficientStockException::class);

    expect($s->stockAt($s->origin, $s->lots[0]))->toBe(3)
        ->and(KardexMovement::query()->where('type', KardexType::Ajuste)->count())->toBe(0);
});

it('RN-06: sin motivo o con cantidad cero se rechaza sin mover stock', function (string $reason, int $quantity, string $code) {
    $s = TransferScenario::make([[30, 10]]);

    try {
        adjustments()->adjust($s->regente, $s->origin->id, $s->lots[0]->id, $quantity, $reason);
        test()->fail('Debía rechazar el ajuste.');
    } catch (AdjustmentNotValidException $e) {
        expect($e->details()['reason'])->toBe($code)
            ->and($e->errorCode())->toBe('AJUSTE_INVALIDO');
    }

    expect(KardexMovement::query()->where('type', KardexType::Ajuste)->count())->toBe(0);
})->with([
    'motivo vacío' => ['', 2, 'MOTIVO_REQUERIDO'],
    'motivo en blanco' => ['   ', 2, 'MOTIVO_REQUERIDO'],
    'cantidad cero' => ['Conteo', 0, 'CANTIDAD_CERO'],
]);

it('solo un regente activo ajusta', function (Closure $user) {
    $s = TransferScenario::make([[30, 10]]);

    expect(fn () => adjustments()->adjust($user(), $s->origin->id, $s->lots[0]->id, 1, 'Conteo'))
        ->toThrow(SegregationOfDutiesException::class);

    expect($s->stockAt($s->origin, $s->lots[0]))->toBe(10);
})->with([
    'auxiliar' => [fn () => User::factory()->auxiliar()->create()],
    'regente inactivo' => [fn () => User::factory()->regente()->create(['is_active' => false])],
]);
