<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use App\Models\Warehouse;

/**
 * Producto por debajo de su stock mínimo en una bodega — RN-11.
 */
final readonly class LowStockAlert
{
    public function __construct(
        public Warehouse $warehouse,
        public Product $product,
        public int $minQuantity,
        /** Unidades en lotes NO vencidos. */
        public int $available,
    ) {}

    public function shortfall(): int
    {
        return $this->minQuantity - $this->available;
    }
}
