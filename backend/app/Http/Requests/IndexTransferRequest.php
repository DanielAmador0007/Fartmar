<?php

namespace App\Http\Requests;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/transfers?status=&warehouse_id=&per_page=
 */
class IndexTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Transfer::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TransferStatus::class)],
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
