<?php

namespace App\Http\Requests;

use App\Domain\Inventory\KardexQuery;
use App\Enums\KardexType;
use App\Models\KardexMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/kardex?warehouse_id=&product_id=&lot_id=&type=&reference_type=&reference_id=&from=&to=&per_page=
 */
class IndexKardexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', KardexMovement::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'lot_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', Rule::enum(KardexType::class)],
            // Un id sin tipo sería ambiguo (dispensación 5 ≠ traslado 5).
            'reference_type' => ['required_with:reference_id', 'string', Rule::in(KardexQuery::REFERENCE_TYPES)],
            'reference_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{warehouse_id: int|null, product_id: int|null, lot_id: int|null, type: string|null, reference_type: string|null, reference_id: int|null, from: string|null, to: string|null, per_page: int}
     */
    public function filters(): array
    {
        return [
            'warehouse_id' => $this->has('warehouse_id') ? $this->integer('warehouse_id') : null,
            'product_id' => $this->has('product_id') ? $this->integer('product_id') : null,
            'lot_id' => $this->has('lot_id') ? $this->integer('lot_id') : null,
            'type' => $this->has('type') ? $this->string('type')->toString() : null,
            'reference_type' => $this->has('reference_type') ? $this->string('reference_type')->toString() : null,
            'reference_id' => $this->has('reference_id') ? $this->integer('reference_id') : null,
            'from' => $this->has('from') ? $this->string('from')->toString() : null,
            'to' => $this->has('to') ? $this->string('to')->toString() : null,
            'per_page' => $this->integer('per_page', 50),
        ];
    }
}
