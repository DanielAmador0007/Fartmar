<?php

namespace App\Domain\Exceptions;

/**
 * RN-06: un ajuste de inventario exige cantidad distinta de cero y motivo.
 */
final class AdjustmentNotValidException extends BusinessRuleException
{
    public static function reasonRequired(): self
    {
        return new self('Todo ajuste de inventario necesita un motivo (por ejemplo: "conteo físico", "baja por vencimiento").', ['reason' => 'MOTIVO_REQUERIDO']);
    }

    public static function zeroQuantity(): self
    {
        return new self('La cantidad del ajuste no puede ser cero: use un número positivo para sumar o negativo para restar.', ['reason' => 'CANTIDAD_CERO']);
    }

    public function errorCode(): string
    {
        return 'AJUSTE_INVALIDO';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
