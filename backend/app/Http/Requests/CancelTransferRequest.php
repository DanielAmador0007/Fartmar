<?php

namespace App\Http\Requests;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;

class CancelTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = $this->route('transfer');

        return $transfer instanceof Transfer
            && ($this->user()?->can('cancel', $transfer) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indique el motivo de la anulación.',
            'reason.min' => 'El motivo de la anulación es muy corto.',
        ];
    }
}
