<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\StockMinimum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Producto del catálogo con sus mínimos de stock por bodega (RN-11).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'presentation' => $this->presentation,
            'unit' => $this->unit,
            'is_controlled' => $this->is_controlled,
            'stock_minimums' => $this->whenLoaded('stockMinimums', fn () => $this->stockMinimums
                ->sortBy('warehouse_id')
                ->map(fn (StockMinimum $min) => [
                    'warehouse_id' => $min->warehouse_id,
                    'min_quantity' => $min->min_quantity,
                ])->values()),
        ];
    }
}
