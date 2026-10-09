<?php

namespace App\Domain\Exceptions;

/**
 * RN-03: no hay existencias suficientes (en lotes no vencidos) para la cantidad pedida.
 */
final class InsufficientStockException extends BusinessRuleException
{
    public static function forProduct(int $warehouseId, int $productId, int $requested, int $available): self
    {
        return new self(
            "No hay existencias suficientes en la bodega: se pidieron {$requested} unidades y solo hay {$available} disponibles en lotes no vencidos.",
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'requested' => $requested,
                'available' => $available,
            ],
        );
    }

    /**
     * Para la asignación FEFO pura (no conoce bodega ni producto).
     */
    public static function forAllocation(int $requested, int $available): self
    {
        return new self(
            "No hay existencias suficientes: se pidieron {$requested} unidades y solo hay {$available} disponibles en lotes no vencidos.",
            ['requested' => $requested, 'available' => $available],
        );
    }

    public function errorCode(): string
    {
        return 'STOCK_INSUFICIENTE';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * Devuelve la misma excepción con bodega y producto en los detalles.
     */
    public function withContext(int $warehouseId, int $productId): self
    {
        $details = $this->details();

        return self::forProduct($warehouseId, $productId, (int) $details['requested'], (int) $details['available']);
    }
}
