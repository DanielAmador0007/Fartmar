<?php

namespace App\Http\Requests;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/stocks/in-transit?warehouse_id=&origin_warehouse_id=&destination_warehouse_id=&product_id=&lot_id=&per_page=
 *
 * Es una consulta de inventario: mismos permisos que /stocks (StockPolicy).
 */
class IndexInTransitStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Stock::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'origin_warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'destination_warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'lot_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{warehouse_id: int|null, origin_warehouse_id: int|null, destination_warehouse_id: int|null, product_id: int|null, lot_id: int|null, per_page: int}
     */
    public function filters(): array
    {
        $id = fn (string $key): ?int => $this->has($key) ? $this->integer($key) : null;

        return [
            'warehouse_id' => $id('warehouse_id'),
            'origin_warehouse_id' => $id('origin_warehouse_id'),
            'destination_warehouse_id' => $id('destination_warehouse_id'),
            'product_id' => $id('product_id'),
            'lot_id' => $id('lot_id'),
            'per_page' => $this->integer('per_page', 50),
        ];
    }
}
