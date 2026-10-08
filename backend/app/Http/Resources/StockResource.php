<?php

namespace App\Http\Resources;

use App\Domain\Inventory\BusinessDate;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Stock
 */
class StockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $expiresAt = $this->lot->expires_at->toDateString();

        return [
            'id' => $this->id,
            'warehouse' => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ],
            'product' => [
                'id' => $this->product->id,
                'code' => $this->product->code,
                'name' => $this->product->name,
                'is_controlled' => $this->product->is_controlled,
            ],
            'lot' => [
                'id' => $this->lot->id,
                'lot_number' => $this->lot->lot_number,
                'expires_at' => $expiresAt,
                // RN-01: vencido = expires_at < hoy (zona de negocio).
                'is_expired' => $expiresAt < BusinessDate::today()->toDateString(),
            ],
            'quantity' => $this->quantity,
        ];
    }
}
