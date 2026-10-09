<?php

namespace App\Http\Requests;

use App\Models\KardexMovement;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/inventory-adjustments
 *
 * quantity con signo: positivo suma, negativo resta (nunca 0).
 */
class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjust', KardexMovement::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'min:1', 'exists:warehouses,id'],
            'lot_id' => ['required', 'integer', 'min:1', 'exists:lots,id'],
            'quantity' => ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.not_in' => 'La cantidad del ajuste no puede ser cero: positivo suma, negativo resta.',
            'reason.required' => 'Todo ajuste de inventario necesita un motivo.',
            'reason.min' => 'El motivo del ajuste es muy corto.',
        ];
    }
}
