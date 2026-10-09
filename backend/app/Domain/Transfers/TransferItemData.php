<?php

namespace App\Domain\Transfers;

final readonly class TransferItemData
{
    public function __construct(
        public int $productId,
        public int $quantity,
    ) {}
}
