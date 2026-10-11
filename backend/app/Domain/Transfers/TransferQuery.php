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
     * Relaciones del listado: bodegas y TODOS los actores, para que *_by
     * tenga la misma forma que en el detalle (TransferResource::RELATIONS las
     * incluye). Eager loading: una consulta por relación, sin N+1.
     */
    public const LIST_RELATIONS = [
        'originWarehouse',
        'destinationWarehouse',
        'requester',
        'approver',
        'dispatcher',
        'receiver',
        'canceller',
    ];

    /**
     * warehouse_id filtra traslados donde la bodega es origen O destino.
     *
     * @param  array{status?: string|null, warehouse_id?: int|null, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Transfer>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        return Transfer::query()
            ->with(self::LIST_RELATIONS)
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
