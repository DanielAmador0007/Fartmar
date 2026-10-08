<?php

namespace App\Domain\Dispensing;

final readonly class DispenseItemData
{
    public function __construct(
        public int $prescriptionItemId,
        public int $quantity,
    ) {}
}
