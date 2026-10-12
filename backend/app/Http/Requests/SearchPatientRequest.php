<?php

namespace App\Http\Requests;

use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/patients/search { document_type, document_number }.
 *
 * Va por POST y no por GET con query string (S-69): la URL queda en los
 * logs de acceso del servidor web y del proxy, en el historial del navegador
 * y en el header Referer; el cuerpo no.
 */
class SearchPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Patient::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:CC,TI,CE,RC,PA'],
            // Se aceptan puntos, guiones y espacios ("1.032.456.789"); DocumentHasher los quita.
            'document_number' => ['required', 'string', 'min:3', 'max:30', 'regex:/\A[0-9A-Za-z .\-]+\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.in' => 'Tipo de documento no válido (CC, TI, CE, RC o PA).',
            'document_number.regex' => 'El número de documento solo puede tener letras, números, puntos, guiones y espacios.',
        ];
    }
}
