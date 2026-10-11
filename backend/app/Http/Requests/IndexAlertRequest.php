<?php

namespace App\Http\Requests;

use App\Domain\Inventory\AlertService;
use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/alerts?warehouse_id=&limit=
 *
 * Las alertas son una consulta de inventario: mismos permisos que /stocks.
 * limit: máximo de filas por lista (100 por defecto, hasta 500; S-62).
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
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.AlertService::MAX_LIMIT],
        ];
    }

    public function warehouseId(): ?int
    {
        return $this->has('warehouse_id') ? $this->integer('warehouse_id') : null;
    }

    public function limit(): int
    {
        return $this->integer('limit', AlertService::DEFAULT_LIMIT);
    }
}
