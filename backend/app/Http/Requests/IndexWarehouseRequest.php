<?php

namespace App\Http\Requests;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/warehouses?per_page=
 */
class IndexWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Warehouse::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{per_page: int}
     */
    public function filters(): array
    {
        return [
            'per_page' => $this->integer('per_page', 100),
        ];
    }
}
