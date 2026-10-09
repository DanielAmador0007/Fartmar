<?php

namespace App\Domain\Exceptions;

/**
 * Cambio de estado no permitido (p. ej. autorizar una dispensación ya
 * completada o rechazada). Reutilizable por la máquina de estados de traslados.
 */
final class InvalidTransitionException extends BusinessRuleException
{
    public static function between(string $entity, int $id, string $from, string $to): self
    {
        return new self(
            "No se puede pasar de {$from} a {$to}: la operación no está permitida en el estado actual.",
            ['entity' => $entity, 'id' => $id, 'from' => $from, 'to' => $to],
        );
    }

    public function errorCode(): string
    {
        return 'TRANSICION_INVALIDA';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
