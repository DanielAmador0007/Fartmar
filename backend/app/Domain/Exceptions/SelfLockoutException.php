<?php

namespace App\Domain\Exceptions;

/**
 * Un admin no puede desactivarse ni quitarse el rol admin a sí mismo (S-72):
 * cerraría su propia sesión y podría dejar el sistema sin administradores.
 */
final class SelfLockoutException extends BusinessRuleException
{
    public static function make(): self
    {
        return new self(
            'No puede desactivar su propia cuenta ni quitarse el rol de administrador; pídaselo a otro administrador.',
            ['reason' => 'AUTOBLOQUEO'],
        );
    }

    public function errorCode(): string
    {
        return 'OPERACION_NO_PERMITIDA';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
