<?php

namespace App\Domain\Transfers;

/**
 * Datos para crear un traslado en BORRADOR.
 *
 * $notes es texto libre NO confiable (lo escribe un usuario y más adelante
 * lo puede leer el asistente IA): solo se guarda y se devuelve como dato.
 */
final readonly class TransferData
{
    /**
     * @param  list<TransferItemData>  $items
     */
    public function __construct(
        public int $originWarehouseId,
        public int $destinationWarehouseId,
        public array $items,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array{origin_warehouse_id: int, destination_warehouse_id: int, notes?: string|null, items: list<array{product_id: int, quantity: int}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['origin_warehouse_id'],
            (int) $data['destination_warehouse_id'],
            array_map(fn (array $item) => new TransferItemData((int) $item['product_id'], (int) $item['quantity']), $data['items']),
            $data['notes'] ?? null,
        );
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        return array_map(fn (TransferItemData $item) => $item->productId, $this->items);
    }
}
