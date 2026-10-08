<?php

namespace App\Database;

use PDOException;
use Throwable;

/**
 * Lee de forma segura qué error devolvió PostgreSQL.
 *
 * - SQLSTATE: errorInfo[0] del driver (p. ej. 23514 = check_violation,
 *   23505 = unique_violation, 40P01 = deadlock, 40001 = serialización).
 * - Nombre del constraint: se extrae de la PRIMERA línea del mensaje del
 *   servidor (errorInfo[2]), que tiene formato fijo:
 *     ERROR:  new row for relation "stocks" violates check constraint "stocks_quantity_non_negative"
 *     ERROR:  duplicate key value violates unique constraint "dispensations_idempotency_key_unique"
 *   NO se busca en getMessage() de la QueryException, porque ese texto
 *   incluye el SQL y los valores enviados (un valor podría contener el nombre
 *   de otro constraint y engañar a un str_contains).
 *
 * PDO no expone el campo "constraint_name" del protocolo de PostgreSQL, por
 * eso se lee del mensaje. Supuesto: lc_messages en inglés (valor por defecto
 * de la imagen postgres:16-alpine). Si no se reconoce, devuelve null y el
 * error se trata como inesperado (nunca como otro error de negocio).
 */
final class PostgresError
{
    public const CHECK_VIOLATION = '23514';

    public const UNIQUE_VIOLATION = '23505';

    public const DEADLOCK_DETECTED = '40P01';

    public const SERIALIZATION_FAILURE = '40001';

    public static function sqlState(Throwable $e): ?string
    {
        if (! $e instanceof PDOException) {
            return null;
        }

        $state = $e->errorInfo[0] ?? $e->getCode();

        return is_string($state) && $state !== '' ? $state : null;
    }

    public static function constraint(Throwable $e): ?string
    {
        if (! $e instanceof PDOException) {
            return null;
        }

        $serverMessage = $e->errorInfo[2] ?? null;
        if (! is_string($serverMessage)) {
            return null;
        }

        $firstLine = strtok($serverMessage, "\n");
        if ($firstLine === false) {
            return null;
        }

        return preg_match('/violates (?:check|unique) constraint "([^"]+)"/', $firstLine, $m) === 1 ? $m[1] : null;
    }

    public static function isViolationOf(Throwable $e, string $sqlState, string $constraint): bool
    {
        return self::sqlState($e) === $sqlState && self::constraint($e) === $constraint;
    }

    /**
     * Deadlock o fallo de serialización: PostgreSQL deshizo la transacción y
     * repetirla probablemente funcione.
     */
    public static function isConcurrencyFailure(Throwable $e): bool
    {
        return in_array(self::sqlState($e), [self::DEADLOCK_DETECTED, self::SERIALIZATION_FAILURE], true);
    }
}
