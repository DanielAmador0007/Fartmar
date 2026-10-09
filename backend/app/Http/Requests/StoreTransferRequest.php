<?php

namespace App\Http\Requests;

use App\Domain\Transfers\TransferData;
use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/transfers: crea un traslado en BORRADOR.
 *
 * notes es texto libre NO confiable: solo se limita su longitud; nunca se
 * interpreta (la Fase 6 lo tratará como dato no confiable ante la IA).
 */
class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Transfer::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origin_warehouse_id' => ['required', 'integer', 'min:1', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'min:1', 'exists:warehouses,id', 'different:origin_warehouse_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'list', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:product_id,quantity'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination_warehouse_id.different' => 'La bodega de destino debe ser distinta de la de origen.',
            'items.required' => 'Agregue al menos un producto al traslado.',
            'items.*.product_id.distinct' => 'Un producto no puede repetirse en el traslado.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
        ];
    }

    public function transferData(): TransferData
    {
        /** @var array{origin_warehouse_id: int, destination_warehouse_id: int, notes?: string|null, items: list<array{product_id: int, quantity: int}>} $validated */
        $validated = $this->safe()->only(['origin_warehouse_id', 'destination_warehouse_id', 'notes', 'items']);

        return TransferData::fromArray($validated);
    }
}
