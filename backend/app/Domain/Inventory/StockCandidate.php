<?php

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;

/**
 * Existencia de un lote en una bodega, tal como la ve el asignador FEFO.
 */
final readonly class StockCandidate
{
    public function __construct(
        public int $lotId,
        public string $lotNumber,
        public CarbonImmutable $expiresAt,
        public int $quantity,
    ) {}
}
