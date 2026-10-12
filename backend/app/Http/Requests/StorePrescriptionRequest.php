<?php

namespace App\Http\Requests;

use App\Domain\Inventory\BusinessDate;
use App\Models\Prescription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/prescriptions (solo médico).
 *
 * Vigencia (S-70): valid_until desde MAÑANA (hora de Bogotá) y hasta 365
 * días. No se acepta "solo hoy": issued_at se guarda en UTC y en la noche de
 * Bogotá ya es el día siguiente en UTC, lo que violaría el CHECK
 * valid_until >= issued_at::date.
 */
class StorePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Prescription::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = BusinessDate::today();

        return [
            'patient_id' => ['required', 'integer', 'min:1', 'exists:patients,id'],
            'valid_until' => [
                'required',
                'date_format:Y-m-d',
                'after:'.$today->toDateString(),
                'before_or_equal:'.$today->addDays(365)->toDateString(),
            ],
            'items' => ['required', 'list', 'min:1', 'max:20'],
            'items.*' => ['required', 'array:product_id,quantity,instructions'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.instructions' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valid_until.after' => 'La prescripción debe ser vigente al menos hasta mañana.',
            'valid_until.before_or_equal' => 'La vigencia no puede superar un año.',
            'items.required' => 'Agregue al menos un medicamento.',
            'items.*.product_id.distinct' => 'Un medicamento no puede repetirse en la prescripción.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
        ];
    }

    public function validUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('valid_until')->toString())->startOfDay();
    }

    /**
     * @return list<array{product_id: int, quantity: int, instructions?: string|null}>
     */
    public function prescriptionItems(): array
    {
        /** @var list<array{product_id: int, quantity: int, instructions?: string|null}> $items */
        $items = $this->validated('items');

        return array_map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
            'instructions' => isset($item['instructions']) ? trim((string) $item['instructions']) : null,
        ], $items);
    }
}
