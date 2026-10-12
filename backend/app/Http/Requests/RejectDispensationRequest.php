<?php

namespace App\Http\Requests;

use App\Models\Dispensation;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RejectDispensationRequest extends FormRequest
{
    /**
     * Devuelve la respuesta de la Policy (no un bool) para conservar el
     * código SEGREGACION_FUNCIONES cuando el creador intenta rechazar.
     */
    public function authorize(): Response
    {
        $dispensation = $this->route('dispensation');

        return $dispensation instanceof Dispensation
            ? Gate::inspect('reject', $dispensation)
            : Response::deny();
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
