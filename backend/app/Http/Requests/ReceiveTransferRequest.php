<?php

namespace App\Http\Requests;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/transfers/{id}/receive
 *
 * Una entrada por cada lote despachado (transfer_item_lot_id) con lo que
 * llegó: 0..despachado. Que estén todos y que no se pase de lo despachado lo
 * valida TransferService (necesita los lotes del traslado).
 */
class ReceiveTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = $this->route('transfer');

        return $transfer instanceof Transfer
            && ($this->user()?->can('receive', $transfer) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lots' => ['required', 'list', 'min:1', 'max:200'],
            'lots.*' => ['required', 'array:transfer_item_lot_id,quantity_received'],
            'lots.*.transfer_item_lot_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lots.*.quantity_received' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lots.required' => 'Indique la cantidad recibida de cada lote despachado.',
            'lots.*.transfer_item_lot_id.distinct' => 'Un lote no puede repetirse en la recepción.',
            'lots.*.quantity_received.min' => 'La cantidad recibida no puede ser negativa (use 0 si no llegó nada).',
        ];
    }

    /**
     * @return array<int, int> transfer_item_lot_id => cantidad recibida
     */
    public function receivedQuantities(): array
    {
        /** @var list<array{transfer_item_lot_id: int, quantity_received: int}> $lots */
        $lots = $this->validated('lots');

        $received = [];
        foreach ($lots as $lot) {
            $received[(int) $lot['transfer_item_lot_id']] = (int) $lot['quantity_received'];
        }

        return $received;
    }
}
