<?php

namespace App\Http\Requests;

use App\Models\Dispensation;
use Illuminate\Foundation\Http\FormRequest;

class RejectDispensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dispensation = $this->route('dispensation');

        return $dispensation instanceof Dispensation
            && ($this->user()?->can('decide', $dispensation) ?? false);
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
            'reason.required' => 'Indique el motivo del rechazo.',
            'reason.min' => 'El motivo del rechazo es muy corto.',
        ];
    }
}
