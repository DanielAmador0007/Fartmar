<?php

namespace App\Http\Requests;

use App\Enums\DispensationStatus;
use App\Models\Dispensation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/dispensations?status=&warehouse_id=&per_page=
 */
class IndexDispensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Dispensation::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(DispensationStatus::class)],
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{status: string|null, warehouse_id: int|null, per_page: int}
     */
    public function filters(): array
    {
        return [
            'status' => $this->has('status') ? $this->string('status')->toString() : null,
            'warehouse_id' => $this->has('warehouse_id') ? $this->integer('warehouse_id') : null,
            'per_page' => $this->integer('per_page', 50),
        ];
    }
}
