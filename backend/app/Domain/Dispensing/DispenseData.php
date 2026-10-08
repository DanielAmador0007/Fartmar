<?php

namespace App\Domain\Dispensing;

/**
 * Datos de una solicitud de dispensación (ya validados por el Form Request).
 */
final readonly class DispenseData
{
    /**
     * @param  list<DispenseItemData>  $items
     */
    public function __construct(
        public int $patientId,
        public int $prescriptionId,
        public int $warehouseId,
        public array $items,
    ) {}

    /**
     * @param  array{patient_id: int|string, prescription_id: int|string, warehouse_id: int|string, items: list<array{prescription_item_id: int|string, quantity: int|string}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['patient_id'],
            (int) $data['prescription_id'],
            (int) $data['warehouse_id'],
            array_map(
                fn (array $item) => new DispenseItemData((int) $item['prescription_item_id'], (int) $item['quantity']),
                $data['items'],
            ),
        );
    }

    /**
     * Forma canónica (independiente del orden de las líneas) usada para el
     * request_hash de idempotencia.
     *
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        $items = array_map(fn (DispenseItemData $i) => [$i->prescriptionItemId, $i->quantity], $this->items);
        sort($items);

        return [
            'patient_id' => $this->patientId,
            'prescription_id' => $this->prescriptionId,
            'warehouse_id' => $this->warehouseId,
            'items' => $items,
        ];
    }

    /**
     * @return list<int>
     */
    public function prescriptionItemIds(): array
    {
        return array_map(fn (DispenseItemData $i) => $i->prescriptionItemId, $this->items);
    }
}
