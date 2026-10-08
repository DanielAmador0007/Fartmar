<?php

namespace App\Http\Requests;

use App\Domain\Dispensing\DispenseData;
use App\Models\Dispensation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/dispensations. Header Idempotency-Key obligatorio (RN-09).
 */
class StoreDispensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Dispensation::class) ?? false;
    }

    /**
     * La clave viene en el header; se valida junto con el cuerpo.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 8 a 100 caracteres: letras, números, guion y guion bajo (un UUID cumple).
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'patient_id' => ['required', 'integer', 'min:1', 'exists:patients,id'],
            'prescription_id' => ['required', 'integer', 'min:1', 'exists:prescriptions,id'],
            'warehouse_id' => ['required', 'integer', 'min:1', 'exists:warehouses,id'],
            // Lista JSON (no objeto) de líneas con exactamente estas dos claves.
            'items' => ['required', 'list', 'min:1', 'max:20'],
            'items.*' => ['required', 'array:prescription_item_id,quantity'],
            'items.*.prescription_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Falta el header Idempotency-Key (un UUID por cada intento de dispensación).',
            'idempotency_key.*' => 'El header Idempotency-Key no es válido: debe tener entre 8 y 100 caracteres (letras, números, guion o guion bajo), por ejemplo un UUID.',
            'items.required' => 'Indique al menos un medicamento a dispensar.',
            'items.*.prescription_item_id.distinct' => 'Un medicamento de la prescripción no puede repetirse.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->string('idempotency_key')->toString();
    }

    public function dispenseData(): DispenseData
    {
        /** @var array{patient_id: int, prescription_id: int, warehouse_id: int, items: list<array{prescription_item_id: int, quantity: int}>} $validated */
        $validated = $this->safe()->only(['patient_id', 'prescription_id', 'warehouse_id', 'items']);

        return DispenseData::fromArray($validated);
    }
}
