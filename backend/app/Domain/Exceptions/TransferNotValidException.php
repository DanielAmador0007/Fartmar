<?php

namespace App\Domain\Exceptions;

/**
 * Datos de un traslado que no tienen sentido de negocio: misma bodega de
 * origen y destino, sin productos, producto repetido o una recepción que no
 * cuadra con lo despachado.
 */
final class TransferNotValidException extends BusinessRuleException
{
    public static function sameWarehouse(int $warehouseId): self
    {
        return new self(
            'La bodega de origen y la de destino deben ser distintas.',
            ['reason' => 'MISMA_BODEGA', 'warehouse_id' => $warehouseId],
        );
    }

    public static function noItems(): self
    {
        return new self('Agregue al menos un producto al traslado.', ['reason' => 'SIN_PRODUCTOS']);
    }

    /**
     * @param  list<int>  $productIds
     */
    public static function duplicatedProducts(array $productIds): self
    {
        return new self(
            'Un producto no puede aparecer dos veces en el mismo traslado; sume las cantidades en una sola línea.',
            ['reason' => 'PRODUCTO_REPETIDO', 'product_ids' => $productIds],
        );
    }

    public static function reasonRequired(string $action): self
    {
        return new self("Indique el motivo para {$action}.", ['reason' => 'MOTIVO_REQUERIDO']);
    }

    /**
     * @param  list<int>  $missing  lotes despachados sin cantidad recibida
     * @param  list<int>  $unknown  ids que no son lotes de este traslado
     */
    public static function receiptDoesNotMatch(int $transferId, array $missing, array $unknown): self
    {
        return new self(
            'Indique la cantidad recibida de cada lote despachado en este traslado (0 si no llegó nada), y solo de esos lotes.',
            [
                'reason' => 'LOTES_NO_COINCIDEN',
                'transfer_id' => $transferId,
                'missing_transfer_item_lot_ids' => $missing,
                'unknown_transfer_item_lot_ids' => $unknown,
            ],
        );
    }

    public static function receivedOutOfRange(int $transferItemLotId, int $received, int $dispatched): self
    {
        return new self(
            "Se registraron {$received} unidades recibidas de un lote del que se despacharon {$dispatched}; la cantidad recibida debe estar entre 0 y {$dispatched}.",
            [
                'reason' => 'CANTIDAD_RECIBIDA_INVALIDA',
                'transfer_item_lot_id' => $transferItemLotId,
                'received' => $received,
                'dispatched' => $dispatched,
            ],
        );
    }

    public function errorCode(): string
    {
        return 'TRASLADO_INVALIDO';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
