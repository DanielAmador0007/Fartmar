<?php

namespace App\Domain\Dispensing;

use App\Models\Dispensation;

/**
 * Resultado de crear una dispensación. replayed = true si se devolvió una
 * dispensación existente por la misma Idempotency-Key (no se movió stock).
 */
final readonly class DispenseResult
{
    public function __construct(
        public Dispensation $dispensation,
        public bool $replayed,
    ) {}
}
