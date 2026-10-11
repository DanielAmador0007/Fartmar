<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * Reglas de una contraseña NUEVA, en un solo lugar (S-72, S-77):
 * 12+ caracteres con letras y números y como máximo 72 BYTES.
 *
 * bcrypt solo usa los primeros 72 bytes: con más, dos claves que difieren
 * después del byte 72 serían la misma. "max:72" de Laravel cuenta
 * caracteres, no bytes (una tilde ocupa 2), por eso se valida con strlen.
 */
final class PasswordRules
{
    public const MAX_BYTES = 72;

    /**
     * @return list<mixed>
     */
    public static function newPassword(): array
    {
        return [
            'required',
            'string',
            Password::min(12)->letters()->numbers(),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::MAX_BYTES) {
                    $fail('La contraseña no puede superar '.self::MAX_BYTES.' bytes (las tildes y la ñ ocupan 2).');
                }
            },
        ];
    }
}
