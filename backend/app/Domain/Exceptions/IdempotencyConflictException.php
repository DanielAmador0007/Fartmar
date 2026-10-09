<?php

namespace App\Domain\Exceptions;

/**
 * RN-09: se reutilizó una Idempotency-Key con un contenido distinto.
 */
final class IdempotencyConflictException extends BusinessRuleException
{
    public static function forKey(string $key): self
    {
        return new self(
            'Esta solicitud ya se registró antes con datos diferentes. Si es una dispensación nueva, vuelva a intentarlo desde la pantalla (se generará una clave nueva).',
            ['idempotency_key' => $key],
        );
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCIA_CONFLICTO';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
