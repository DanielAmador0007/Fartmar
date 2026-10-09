<?php

namespace App\Http\Resources;

use App\Domain\Inventory\ExpiringLotAlert;
use App\Domain\Inventory\LowStockAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Alertas de inventario (RN-11): vencimientos y stock mínimo.
 *
 * @property array{today: string, expiry_days: int, expiring: list<ExpiringLotAlert>, low_stock: list<LowStockAlert>} $resource
 */
class AlertsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'today' => $this->resource['today'],
            'expiry_days' => $this->resource['expiry_days'],
            'expiring_lots' => array_map(fn (ExpiringLotAlert $alert) => [
                'stock_id' => $alert->stock->id,
                'warehouse' => TransferResource::warehouse($alert->stock->warehouse),
                'product' => [
                    'id' => $alert->stock->product->id,
                    'code' => $alert->stock->product->code,
                    'name' => $alert->stock->product->name,
                ],
                'lot' => [
                    'id' => $alert->stock->lot->id,
                    'lot_number' => $alert->stock->lot->lot_number,
                    'expires_at' => $alert->stock->lot->expires_at->toDateString(),
                ],
                'quantity' => $alert->stock->quantity,
                'days_to_expire' => $alert->daysToExpire,
                'is_expired' => $alert->isExpired(),
            ], $this->resource['expiring']),
            'low_stock' => array_map(fn (LowStockAlert $alert) => [
                'warehouse' => TransferResource::warehouse($alert->warehouse),
                'product' => [
                    'id' => $alert->product->id,
                    'code' => $alert->product->code,
                    'name' => $alert->product->name,
                ],
                'min_quantity' => $alert->minQuantity,
                'available' => $alert->available,
                'shortfall' => $alert->shortfall(),
            ], $this->resource['low_stock']),
        ];
    }
}
