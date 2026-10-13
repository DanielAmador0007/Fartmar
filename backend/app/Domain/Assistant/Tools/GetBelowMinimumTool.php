<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Inventory\AlertService;
use App\Domain\Inventory\LowStockAlert;
use App\Models\Stock;
use App\Models\User;

/**
 * Productos por debajo de su stock mínimo en una bodega (RN-11).
 */
final class GetBelowMinimumTool extends AssistantTool
{
    public function __construct(private readonly AlertService $alerts) {}

    public function name(): string
    {
        return 'get_below_minimum';
    }

    public function description(): string
    {
        return 'Productos cuyo disponible (lotes no vencidos) está por debajo del stock mínimo de la bodega. Filtro opcional por bodega.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => self::warehouseCodeSchema(),
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
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

        $alerts = $this->alerts->lowStock($warehouse?->id, self::MAX_ROWS + 1);

        return [
            'filters' => ['warehouse' => self::warehouseRef($warehouse)],
            'count' => min(count($alerts), self::MAX_ROWS),
            'truncated' => count($alerts) > self::MAX_ROWS,
            'rows' => array_map(fn (LowStockAlert $a) => [
                'warehouse' => self::warehouseRef($a->warehouse),
                'product' => self::productRef($a->product),
                'min_quantity' => $a->minQuantity,
                'available' => $a->available,
                'shortfall' => $a->shortfall(),
            ], array_slice($alerts, 0, self::MAX_ROWS)),
        ];
    }
}
