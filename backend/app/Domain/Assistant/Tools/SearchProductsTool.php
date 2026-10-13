<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Catalog\CatalogQuery;
use App\Models\Product;
use App\Models\User;

/**
 * Resuelve un nombre o código ("acetaminofén", "ACE500") a product_id.
 * Sin distinguir mayúsculas ni tildes (CatalogQuery).
 */
final class SearchProductsTool extends AssistantTool
{
    public function __construct(private readonly CatalogQuery $catalog) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Busca productos del catálogo por nombre o código y devuelve su id. Úsela antes de las demás herramientas cuando la pregunta mencione un medicamento.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Nombre o código del producto, por ejemplo "acetaminofén".'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['query' => ['required', 'string', 'min:2', 'max:100']];
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Product::class);
    }

    public function run(array $args): array
    {
        $products = $this->catalog->products(['q' => trim((string) $args['query']), 'per_page' => 10]);

        return [
            'filters' => ['query' => $args['query']],
            'count' => $products->total(),
            'rows' => collect($products->items())->map(fn (Product $product) => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'presentation' => $product->presentation,
                'is_controlled' => $product->is_controlled,
            ])->values()->all(),
        ];
    }
}
