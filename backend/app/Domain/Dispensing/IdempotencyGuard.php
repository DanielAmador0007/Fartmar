<?php

namespace App\Domain\Dispensing;

use App\Domain\Exceptions\IdempotencyConflictException;
use App\Models\Dispensation;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Idempotencia de la creación de dispensaciones (RN-09).
 *
 * - request_hash = SHA-256 de (usuario + payload canónico). Así, la misma
 *   clave con otro contenido, o usada por otro usuario, es un conflicto (409).
 * - findReplay(): si la clave ya existe con el mismo hash, devuelve la
 *   dispensación original (el llamador no mueve stock).
 * - isKeyCollision(): reconoce la violación del UNIQUE de idempotency_key que
 *   ocurre cuando dos reintentos con la misma clave llegan a la vez.
 *
 * No es final para poder simular la carrera en pruebas (Mockery parcial).
 */
class IdempotencyGuard
{
    private const UNIQUE_CONSTRAINT = 'dispensations_idempotency_key_unique';

    public function hash(int $userId, DispenseData $data): string
    {
        $canonical = ['user_id' => $userId] + $data->canonical();

        return hash('sha256', (string) json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws IdempotencyConflictException si la clave existe con otro contenido.
     */
    public function findReplay(string $key, string $requestHash): ?Dispensation
    {
        $existing = Dispensation::query()->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw IdempotencyConflictException::forKey($key);
        }

        return $existing;
    }

    public function isKeyCollision(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), self::UNIQUE_CONSTRAINT);
    }
}
