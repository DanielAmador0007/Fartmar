<?php

namespace App\Domain\Transfers;

use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Listados de traslados y discrepancias (solo lectura), del más reciente al
 * más antiguo.
 */
final class TransferQuery
{
    /**
     * warehouse_id filtra traslados donde la bodega es origen O destino.
     *
     * @param  array{status?: string|null, warehouse_id?: int|null, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Transfer>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        return Transfer::query()
            ->with(['originWarehouse', 'destinationWarehouse', 'requester'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where(
                fn ($w) => $w->where('origin_warehouse_id', $id)->orWhere('destination_warehouse_id', $id),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);
    }

    /**
     * @param  array{status?: string|null, transfer_id?: int|null, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, TransferDiscrepancy>
     */
    public function discrepancies(array $filters): LengthAwarePaginator
    {
        return TransferDiscrepancy::query()
            ->with(['transferItemLot.lot', 'transferItemLot.transferItem.product', 'transferItemLot.transferItem.transfer', 'resolver'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['transfer_id'] ?? null, fn ($q, $id) => $q->whereHas(
                'transferItemLot.transferItem', fn ($t) => $t->where('transfer_id', $id),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);
    }
}
