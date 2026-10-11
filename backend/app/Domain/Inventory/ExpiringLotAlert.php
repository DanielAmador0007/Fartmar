<?php

namespace App\Domain\Inventory;

use App\Models\Stock;

/**
 * Existencia cuyo lote vence pronto (o ya venció) — RN-11.
 */
final readonly class ExpiringLotAlert
{
    public function __construct(
        public Stock $stock,
        /** 0 = vence hoy (aún se puede usar, S-14); negativo = vencido. */
        public int $daysToExpire,
    ) {}

    public function isExpired(): bool
    {
        return $this->daysToExpire < 0;
    }
}
