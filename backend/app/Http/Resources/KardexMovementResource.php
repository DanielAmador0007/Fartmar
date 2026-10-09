<?php

namespace App\Http\Resources;

use App\Models\KardexMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Movimiento de kardex (RN-06). signed_quantity = quantity × direction, para
 * mostrar entradas en positivo y salidas en negativo sin cálculos en el
 * frontend.
 *
 * @mixin KardexMovement
 */
class KardexMovementResource extends JsonResource
{
    public const RELATIONS = ['warehouse', 'product', 'lot', 'user'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at->toIso8601String(),
            'type' => $this->type->value,
            'direction' => $this->direction,
            'quantity' => $this->quantity,
            'signed_quantity' => $this->quantity * $this->direction,
            'balance_after' => $this->balance_after,
            'warehouse' => TransferResource::warehouse($this->warehouse),
            'product' => [
                'id' => $this->product->id,
                'code' => $this->product->code,
                'name' => $this->product->name,
            ],
            'lot' => [
                'id' => $this->lot->id,
                'lot_number' => $this->lot->lot_number,
                'expires_at' => $this->lot->expires_at->toDateString(),
            ],
            'reason' => $this->reason,
            // Documento que originó el movimiento (dispensation, transfer,
            // transfer_discrepancy) o null en entradas y ajustes manuales.
            'reference' => $this->reference_type === null ? null : [
                'type' => $this->reference_type,
                'id' => $this->reference_id,
            ],
            'user' => new UserSummaryResource($this->user),
        ];
    }
}
