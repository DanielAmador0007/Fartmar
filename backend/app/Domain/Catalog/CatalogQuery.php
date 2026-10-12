<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Catálogos de solo lectura: bodegas y productos.
 */
final class CatalogQuery
{
    /**
     * Todas las bodegas (el modelo no tiene marca de activa/inactiva, S-86),
     * ordenadas por código.
     *
     * @param  array{per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Warehouse>
     */
    public function warehouses(array $filters): LengthAwarePaginator
    {
        return Warehouse::query()
            ->orderBy('code')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 100);
    }

    /**
     * Productos ordenados por nombre. "q" busca, sin distinguir mayúsculas,
     * dentro del nombre o del código (ILIKE de PostgreSQL). Los comodines
     * % y _ que escriba el usuario se escapan: se buscan como texto literal.
     *
     * Incluye los mínimos de stock por bodega (RN-11).
     *
     * @param  array{q?: string|null, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(array $filters): LengthAwarePaginator
    {
        $term = $filters['q'] ?? null;

        return Product::query()
            ->with('stockMinimums')
            ->when($term !== null && $term !== '', function ($query) use ($term) {
                $pattern = '%'.addcslashes((string) $term, '\%_').'%';
                $query->where(fn ($w) => $w->where('name', 'ilike', $pattern)->orWhere('code', 'ilike', $pattern));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);
    }
}
