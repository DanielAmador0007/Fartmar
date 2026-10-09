<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Base de las excepciones de negocio.
 *
 * Cada subclase define un código estable (en español, para el frontend), el
 * estado HTTP y detalles estructurados. bootstrap/app.php las convierte en:
 *
 *   { "error": { "code": "...", "message": "...", "details": { ... } } }
 *
 * El mensaje debe ser entendible por un auxiliar de farmacia y NUNCA incluir
 * datos personales del paciente (solo IDs y cantidades).
 */
abstract class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
