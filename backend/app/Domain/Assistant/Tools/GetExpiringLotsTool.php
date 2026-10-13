<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Inventory\AlertService;
use App\Domain\Inventory\ExpiringLotAlert;
use App\Models\Stock;
use App\Models\User;

/**
 * Lotes con unidades que vencen en los próximos N días (sin los vencidos).
 */
final class GetExpiringLotsTool extends AssistantTool
{
    public const MAX_DAYS = 365;

    public function __construct(private readonly AlertService $alerts) {}

    public function name(): string
    {
        return 'get_expiring_lots';
    }

    public function description(): string
    {
        return 'Lotes con existencias que aún no vencen y vencen dentro de los próximos "days" días (1 a 365). Filtros opcionales por producto y bodega.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_DAYS, 'description' => 'Ventana en días desde hoy.'],
                ...self::productIdSchema(),
                ...self::warehouseCodeSchema(),
            ],
            'required' => ['days'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'between:1,'.self::MAX_DAYS],
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
        $days = (int) $args['days'];
        $warehouse = $this->findWarehouse($args['warehouse_code'] ?? null);
        $product = $this->findProduct($args['product_id'] ?? null);

        $alerts = $this->alerts->lotsExpiringWithin($days, $warehouse?->id, $product?->id, self::MAX_ROWS + 1);

        return [
            'filters' => ['days' => $days, 'product' => self::productRef($product), 'warehouse' => self::warehouseRef($warehouse)],
            'count' => min(count($alerts), self::MAX_ROWS),
            'truncated' => count($alerts) > self::MAX_ROWS,
            'rows' => array_map(fn (ExpiringLotAlert $a) => self::lotRow($a), array_slice($alerts, 0, self::MAX_ROWS)),
        ];
    }
}
