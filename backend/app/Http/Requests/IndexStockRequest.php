<?php

namespace App\Http\Requests;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/stocks?warehouse_id=&product_id=&lot_id=&include_empty=&per_page=
 */
class IndexStockRequest extends FormRequest
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
            'warehouse_id' => ['sometimes', 'integer'],
            'product_id' => ['sometimes', 'integer'],
            'lot_id' => ['sometimes', 'integer'],
            'include_empty' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{warehouse_id?: int|null, product_id?: int|null, lot_id?: int|null, include_empty?: bool, per_page?: int}
     */
    public function filters(): array
    {
        return [
            'warehouse_id' => $this->has('warehouse_id') ? $this->integer('warehouse_id') : null,
            'product_id' => $this->has('product_id') ? $this->integer('product_id') : null,
            'lot_id' => $this->has('lot_id') ? $this->integer('lot_id') : null,
            'include_empty' => $this->boolean('include_empty'),
            'per_page' => $this->integer('per_page', 50),
        ];
    }
}
