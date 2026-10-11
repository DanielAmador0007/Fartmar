<?php

namespace App\Http\Resources;

use App\Models\TransferDiscrepancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Discrepancia de traslado: lote, cuánto faltó y cómo se resolvió.
 * resolution es texto libre del regente (dato no confiable).
 *
 * @mixin TransferDiscrepancy
 */
class TransferDiscrepancyResource extends JsonResource
{
    public const RELATIONS = [
        'transferItemLot.lot',
        'transferItemLot.transferItem.product',
        'transferItemLot.transferItem.transfer',
        'resolver',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $itemLot = $this->transferItemLot;
        $item = $itemLot->transferItem;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'transfer_id' => $item->transfer_id,
            'transfer_status' => $item->transfer->status->value,
            'origin_warehouse_id' => $item->transfer->origin_warehouse_id,
            'destination_warehouse_id' => $item->transfer->destination_warehouse_id,
            'transfer_item_lot_id' => $itemLot->id,
            'product' => [
                'id' => $item->product->id,
                'code' => $item->product->code,
                'name' => $item->product->name,
            ],
            'lot' => [
                'id' => $itemLot->lot->id,
                'lot_number' => $itemLot->lot->lot_number,
                'expires_at' => $itemLot->lot->expires_at->toDateString(),
            ],
            'quantity_dispatched' => $itemLot->quantity_dispatched,
            'quantity_received' => $itemLot->quantity_received,
            'quantity_missing' => $this->quantity_missing,
            'resolution_action' => $this->resolution_action?->value,
            'resolution' => $this->resolution,
            'resolved_by' => $this->resolved_by ? new UserSummaryResource($this->whenLoaded('resolver')) : null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
