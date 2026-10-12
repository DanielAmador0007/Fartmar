<?php

namespace App\Domain\Exceptions;

/**
 * S-77: el usuario tiene una contraseña temporal (asignada por el admin) y
 * debe cambiarla antes de usar la API.
 */
final class PasswordChangeRequiredException extends BusinessRuleException
{
    public static function make(): self
    {
        return new self(
            'Debe cambiar su contraseña temporal antes de continuar.',
            ['action' => 'POST /api/v1/auth/password'],
        );
    }

    public function errorCode(): string
    {
        return 'CAMBIO_CLAVE_REQUERIDO';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
