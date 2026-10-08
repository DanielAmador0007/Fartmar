<?php

namespace App\Domain\Exceptions;

/**
 * Login fallido. El mensaje es el mismo si el correo no existe, la clave es
 * incorrecta o el usuario está inactivo (no revela cuál de los tres).
 */
final class InvalidCredentialsException extends BusinessRuleException
{
    public static function make(): self
    {
        return new self('Correo o contraseña incorrectos, o el usuario está inactivo.');
    }

    public function errorCode(): string
    {
        return 'CREDENCIALES_INVALIDAS';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
