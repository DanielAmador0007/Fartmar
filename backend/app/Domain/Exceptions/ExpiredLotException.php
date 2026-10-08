<?php

namespace App\Domain\Exceptions;

use Carbon\CarbonImmutable;

/**
 * RN-01: un lote vencido nunca se dispensa ni se traslada.
 */
final class ExpiredLotException extends BusinessRuleException
{
    public static function forLot(int $lotId, string $lotNumber, CarbonImmutable $expiresAt): self
    {
        return new self(
            "El lote {$lotNumber} venció el {$expiresAt->toDateString()} y no se puede despachar.",
            ['lot_id' => $lotId, 'lot_number' => $lotNumber, 'expires_at' => $expiresAt->toDateString()],
        );
    }

    public function errorCode(): string
    {
        return 'LOTE_VENCIDO';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
