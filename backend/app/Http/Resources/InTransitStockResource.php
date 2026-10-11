<?php

namespace App\Http\Resources;

use App\Domain\Inventory\BusinessDate;
use App\Models\TransferItemLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unidades de un lote que van en camino en un traslado EN_TRANSITO
 * (salieron del origen y aún no se reciben en el destino).
 *
 * @mixin TransferItemLot
 */
class InTransitStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transfer = $this->transferItem->transfer;
        $expiresAt = $this->lot->expires_at->toDateString();

        return [
            'transfer_id' => $transfer->id,
            'transfer_item_lot_id' => $this->id,
            'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            'origin_warehouse' => new WarehouseSummaryResource($transfer->originWarehouse),
            'destination_warehouse' => new WarehouseSummaryResource($transfer->destinationWarehouse),
            'product' => [
                'id' => $this->product->id,
                'code' => $this->product->code,
                'name' => $this->product->name,
            ],
            'lot' => [
                'id' => $this->lot->id,
                'lot_number' => $this->lot->lot_number,
                'expires_at' => $expiresAt,
                // Puede vencer mientras viaja; igual se recibe (S-48).
                'is_expired' => $expiresAt < BusinessDate::today()->toDateString(),
            ],
            'quantity' => $this->quantity_dispatched,
        ];
    }
}
