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
     * Letras con tilde (y la ñ) y su equivalente sin tilde, en el mismo orden,
     * para translate() de PostgreSQL. Así "acetaminofen" encuentra
     * "Acetaminofén" sin instalar la extensión unaccent.
     */
    private const ACCENTED = 'ÁÉÍÓÚÜÑáéíóúüñ';

    private const UNACCENTED = 'AEIOUUNaeiouun';

    /**
     * Productos ordenados por nombre. "q" busca, sin distinguir mayúsculas
     * ni tildes, dentro del nombre o del código (ILIKE de PostgreSQL). Los
     * comodines % y _ que escriba el usuario se escapan: se buscan como texto
     * literal.
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
                $pattern = '%'.addcslashes(self::withoutAccents((string) $term), '\%_').'%';
                $query->where(fn ($w) => $w
                    ->whereRaw('translate(name, ?, ?) ILIKE ?', [self::ACCENTED, self::UNACCENTED, $pattern])
                    ->orWhere('code', 'ilike', $pattern));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);
    }

    /**
     * Quita tildes del texto buscado con la misma tabla que se usa en SQL.
     */
    public static function withoutAccents(string $text): string
    {
        return strtr($text, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
