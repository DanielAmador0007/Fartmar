<?php

/*
| FefoAllocator (RN-01, RN-02): función pura, sin BD.
*/

use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Inventory\FefoAllocator;
use App\Domain\Inventory\LotAllocation;
use App\Domain\Inventory\StockCandidate;
use Carbon\CarbonImmutable;

function candidate(int $lotId, string $expiresAt, int $quantity): StockCandidate
{
    return new StockCandidate($lotId, "L-{$lotId}", CarbonImmutable::parse($expiresAt), $quantity);
}

/**
 * @param  list<LotAllocation>  $allocations
 * @return list<array{0: int, 1: int}> [lot_id, cantidad]
 */
function summary(array $allocations): array
{
    return array_map(fn (LotAllocation $a) => [$a->lotId, $a->quantity], $allocations);
}

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-10-08');
    $this->fefo = new FefoAllocator;
});

it('RN-02: toma primero el lote que vence antes, sin importar el orden de entrada', function () {
    $stocks = [
        candidate(1, '2027-06-01', 10),
        candidate(2, '2026-12-01', 10),
        candidate(3, '2027-01-15', 10),
    ];

    expect(summary($this->fefo->allocate($stocks, 5, $this->today)))->toBe([[2, 5]]);
});

it('RN-02: reparte entre varios lotes en orden FEFO cuando uno no alcanza', function () {
    $stocks = [
        candidate(3, '2027-03-01', 100),
        candidate(1, '2026-11-01', 4),
        candidate(2, '2026-12-01', 6),
    ];

    expect(summary($this->fefo->allocate($stocks, 15, $this->today)))->toBe([[1, 4], [2, 6], [3, 5]]);
});

it('RN-02: a igual fecha de vencimiento desempata por lot_id ascendente', function () {
    $stocks = [
        candidate(9, '2026-12-01', 5),
        candidate(4, '2026-12-01', 5),
        candidate(7, '2026-12-01', 5),
    ];

    expect(summary($this->fefo->allocate($stocks, 8, $this->today)))->toBe([[4, 5], [7, 3]]);
});

it('RN-01: excluye lotes vencidos aunque venzan primero', function () {
    $stocks = [
        candidate(1, '2026-10-07', 50), // venció ayer
        candidate(2, '2026-12-01', 10),
    ];

    expect(summary($this->fefo->allocate($stocks, 10, $this->today)))->toBe([[2, 10]]);
});

it('S-14: un lote que vence hoy todavía se puede dispensar', function () {
    $stocks = [candidate(1, '2026-10-08', 3), candidate(2, '2026-12-01', 10)];

    expect(summary($this->fefo->allocate($stocks, 4, $this->today)))->toBe([[1, 3], [2, 1]]);
});

it('ignora lotes sin existencias', function () {
    $stocks = [candidate(1, '2026-11-01', 0), candidate(2, '2026-12-01', 2)];

    expect(summary($this->fefo->allocate($stocks, 2, $this->today)))->toBe([[2, 2]]);
});

it('RN-03: lanza STOCK_INSUFICIENTE con disponible vs solicitado (sin contar vencidos)', function () {
    $stocks = [
        candidate(1, '2026-01-01', 100), // vencido: no cuenta como disponible
        candidate(2, '2026-12-01', 3),
        candidate(3, '2027-01-01', 4),
    ];

    try {
        $this->fefo->allocate($stocks, 8, $this->today);
        $this->fail('Debía lanzar InsufficientStockException');
    } catch (InsufficientStockException $e) {
        expect($e->errorCode())->toBe('STOCK_INSUFICIENTE')
            ->and($e->httpStatus())->toBe(409)
            ->and($e->details())->toBe(['requested' => 8, 'available' => 7]);
    }
});

it('lanza STOCK_INSUFICIENTE si no hay ningún lote', function () {
    $this->fefo->allocate([], 1, $this->today);
})->throws(InsufficientStockException::class);

it('rechaza cantidades no positivas', function () {
    $this->fefo->allocate([candidate(1, '2026-12-01', 5)], 0, $this->today);
})->throws(InvalidArgumentException::class);

it('la suma asignada es exactamente la cantidad pedida', function () {
    $stocks = [candidate(1, '2026-11-01', 7), candidate(2, '2026-11-02', 7), candidate(3, '2026-11-03', 7)];

    $allocations = $this->fefo->allocate($stocks, 21, $this->today);

    expect(array_sum(array_map(fn (LotAllocation $a) => $a->quantity, $allocations)))->toBe(21);
});
