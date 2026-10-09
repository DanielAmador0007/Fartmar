<?php

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;

/**
 * Resultado de FEFO: cuántas unidades salen de un lote.
 */
final readonly class LotAllocation
{
    public function __construct(
        public int $lotId,
        public string $lotNumber,
        public CarbonImmutable $expiresAt,
        public int $quantity,
    ) {}
}
