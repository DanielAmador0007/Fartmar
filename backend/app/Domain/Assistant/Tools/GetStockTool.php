<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Inventory\BusinessDate;
use App\Domain\Inventory\InventoryQuery;
use App\Models\Stock;
use App\Models\User;

/**
 * Existencias con unidades por bodega + lote (InventoryQuery, orden FEFO).
 */
final class GetStockTool extends AssistantTool
{
    public function __construct(private readonly InventoryQuery $inventory) {}

    public function name(): string
    {
        return 'get_stock';
    }

    public function description(): string
    {
        return 'Existencias actuales por bodega y lote (cantidad, vencimiento, si está vencido). Filtros opcionales por producto y bodega.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [...self::productIdSchema(), ...self::warehouseCodeSchema()],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'min:1', 'exists:products,id'],
            'warehouse_code' => ['nullable', 'string', 'max:20', 'exists:warehouses,code'],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Stock::class);
    }

    public function run(array $args): array
    {
        $warehouse = $this->findWarehouse($args['warehouse_code'] ?? null);
        $product = $this->findProduct($args['product_id'] ?? null);
        $today = BusinessDate::today()->toDateString();

        $stocks = $this->inventory->search([
            'warehouse_id' => $warehouse?->id,
            'product_id' => $product?->id,
            'per_page' => self::MAX_ROWS,
        ]);

        $totals = $this->inventory->totals(['warehouse_id' => $warehouse?->id, 'product_id' => $product?->id]);

        $rows = collect($stocks->items())->map(fn (Stock $stock) => [
            'warehouse' => self::warehouseRef($stock->warehouse),
            'product' => self::productRef($stock->product),
            'lot_number' => $stock->lot->lot_number,
            'expires_at' => $stock->lot->expires_at->toDateString(),
            // RN-01: un lote vencido no se puede dispensar ni trasladar.
            'is_expired' => $stock->lot->expires_at->toDateString() < $today,
            'quantity' => $stock->quantity,
        ])->values()->all();

        return [
            'filters' => ['product' => self::productRef($product), 'warehouse' => self::warehouseRef($warehouse)],
            'count' => $stocks->total(),
            'truncated' => $stocks->total() > count($rows),
            // Sobre TODAS las existencias del filtro, aunque las filas estén recortadas.
            'total_quantity' => $totals['total'],
            'available_quantity' => $totals['available'],
            'rows' => $rows,
        ];
    }
}
