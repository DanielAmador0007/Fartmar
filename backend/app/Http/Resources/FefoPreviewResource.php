<?php

namespace App\Http\Resources;

use App\Domain\Dispensing\FefoPreview;
use App\Domain\Inventory\LotAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property FefoPreview $resource
 */
class FefoPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $preview = $this->resource;

        return [
            'warehouse_id' => $preview->warehouseId,
            'product' => [
                'id' => $preview->product->id,
                'code' => $preview->product->code,
                'name' => $preview->product->name,
                'is_controlled' => $preview->product->is_controlled,
            ],
            'quantity' => $preview->quantity,
            // Un controlado se crea pendiente y los lotes se fijan al autorizar:
            // la vista previa es orientativa.
            'requires_authorization' => $preview->product->is_controlled,
            'lots' => array_map(fn (LotAllocation $a) => [
                'lot_id' => $a->lotId,
                'lot_number' => $a->lotNumber,
                'expires_at' => $a->expiresAt->toDateString(),
                'quantity' => $a->quantity,
            ], $preview->allocations),
        ];
    }
}
