<?php

namespace App\Http\Requests;

use App\Models\Dispensation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/dispensations/preview?warehouse_id=&product_id=&quantity=
 */
class PreviewDispensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Dispensation::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
