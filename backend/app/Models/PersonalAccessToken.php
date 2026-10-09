<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token personal de Sanctum con una validación extra del formato "id|secreto".
 *
 * Sanctum acepta cualquier cadena de dígitos como id y hace find($id): un id
 * fuera del rango de bigint ("99999999999999999999|x") hacía fallar la
 * consulta en PostgreSQL con un 500 ANTES de autenticar (y, con APP_DEBUG,
 * exponía el SQL). Aquí ese token simplemente no existe (401).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** Ids de 1 a 18 dígitos: siempre caben en bigint (máx. 9.22e18). */
    private const ID_PATTERN = '/^[1-9][0-9]{0,17}$/';

    /**
     * @param  string  $token
     * @return static|null
     */
    public static function findToken($token)
    {
        if (str_contains($token, '|')) {
            [$id] = explode('|', $token, 2);

            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                return null;
            }
        }

        return parent::findToken($token);
    }
}
