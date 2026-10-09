<?php

namespace App\Domain\Dispensing;

use App\Domain\Inventory\LotAllocation;
use App\Models\Product;

/**
 * Lotes que saldrían HOY por FEFO para una cantidad (sin mover stock).
 */
final readonly class FefoPreview
{
    /**
     * @param  list<LotAllocation>  $allocations
     */
    public function __construct(
        public int $warehouseId,
        public Product $product,
        public int $quantity,
        public array $allocations,
    ) {}
}
