<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Catalog\CatalogQuery;
use App\Models\User;
use App\Models\Warehouse;

final class ListWarehousesTool extends AssistantTool
{
    public function __construct(private readonly CatalogQuery $catalog) {}

    public function name(): string
    {
        return 'list_warehouses';
    }

    public function description(): string
    {
        return 'Lista las bodegas con su código y nombre.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function rules(): array
    {
        return [];
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Warehouse::class);
    }

    public function run(array $args): array
    {
        $warehouses = $this->catalog->warehouses(['per_page' => self::MAX_ROWS]);

        return [
            'filters' => (object) [],
            'count' => $warehouses->total(),
            'rows' => collect($warehouses->items())->map(fn (Warehouse $w) => self::warehouseRef($w))->values()->all(),
        ];
    }
}
