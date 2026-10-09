<?php

namespace App\Http\Requests;

use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/alerts?warehouse_id=
 *
 * Las alertas son una consulta de inventario: mismos permisos que /stocks.
 */
class IndexAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Stock::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function warehouseId(): ?int
    {
        return $this->has('warehouse_id') ? $this->integer('warehouse_id') : null;
    }
}
