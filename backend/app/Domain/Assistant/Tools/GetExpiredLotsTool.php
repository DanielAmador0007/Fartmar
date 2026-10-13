<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Inventory\AlertService;
use App\Domain\Inventory\ExpiringLotAlert;
use App\Models\Stock;
use App\Models\User;

/**
 * Lotes YA vencidos que todavía tienen unidades (hay que darlos de baja).
 */
final class GetExpiredLotsTool extends AssistantTool
{
    public function __construct(private readonly AlertService $alerts) {}

    public function name(): string
    {
        return 'get_expired_lots';
    }

    public function description(): string
    {
        return 'Lotes vencidos que todavía tienen existencias (no se pueden dispensar ni trasladar). Filtros opcionales por producto y bodega.';
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

        $alerts = $this->alerts->expiredLots($warehouse?->id, $product?->id, self::MAX_ROWS + 1);

        return [
            'filters' => ['product' => self::productRef($product), 'warehouse' => self::warehouseRef($warehouse)],
            'count' => min(count($alerts), self::MAX_ROWS),
            'truncated' => count($alerts) > self::MAX_ROWS,
            'rows' => array_map(fn (ExpiringLotAlert $a) => self::lotRow($a), array_slice($alerts, 0, self::MAX_ROWS)),
        ];
    }
}
