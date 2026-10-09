<?php

namespace App\Http\Requests;

use App\Enums\DiscrepancyStatus;
use App\Models\TransferDiscrepancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/transfer-discrepancies?status=&transfer_id=&per_page=
 */
class IndexDiscrepancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', TransferDiscrepancy::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(DiscrepancyStatus::class)],
            'transfer_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{status: string|null, transfer_id: int|null, per_page: int}
     */
    public function filters(): array
    {
        return [
            'status' => $this->has('status') ? $this->string('status')->toString() : null,
            'transfer_id' => $this->has('transfer_id') ? $this->integer('transfer_id') : null,
            'per_page' => $this->integer('per_page', 50),
        ];
    }
}
