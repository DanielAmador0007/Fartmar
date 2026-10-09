<?php

namespace App\Domain\Inventory;

use App\Models\Stock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Consulta de existencias (solo lectura) con filtros por bodega, producto y
 * lote, en orden FEFO dentro de cada bodega + producto.
 */
final class InventoryQuery
{
    /**
     * @param  array{warehouse_id?: int|null, product_id?: int|null, lot_id?: int|null, include_empty?: bool, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Stock>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        return Stock::query()
            ->select('stocks.*')
            ->join('lots', 'lots.id', '=', 'stocks.lot_id')
            ->with(['warehouse', 'product', 'lot'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('stocks.warehouse_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('stocks.product_id', $id))
            ->when($filters['lot_id'] ?? null, fn ($q, $id) => $q->where('stocks.lot_id', $id))
            ->when(! ($filters['include_empty'] ?? false), fn ($q) => $q->where('stocks.quantity', '>', 0))
            ->orderBy('stocks.warehouse_id')
            ->orderBy('stocks.product_id')
            ->orderBy('lots.expires_at')
            ->orderBy('stocks.lot_id')
            ->paginate($filters['per_page'] ?? 50);
    }
}
