<?php

namespace App\Domain\Transfers;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
use App\Models\TransferItemLot;
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
     * Stock en tránsito (M1): unidades que ya salieron del origen
     * (SALIDA_TRASLADO) y aún no entran al destino. Son los lotes despachados
     * (transfer_item_lots) de traslados EN_TRANSITO sin recepción registrada
     * (quantity_received IS NULL).
     *
     * Una fila por traslado + lote. Como un traslado tiene un producto por
     * línea y cada lote aparece una vez por línea (UNIQUE), esa fila ya es el
     * total por origen, destino, producto y lote de ese traslado; se conserva
     * transfer_id para poder ir al traslado. Para el total de un lote en
     * camino entre dos bodegas, se suman las filas con el mismo lote.
     *
     * Orden: más antiguo despachado primero (lo que lleva más tiempo en
     * camino), luego traslado, producto y lote.
     *
     * @param  array{warehouse_id?: int|null, origin_warehouse_id?: int|null, destination_warehouse_id?: int|null, product_id?: int|null, lot_id?: int|null, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, TransferItemLot>
     */
    public function inTransit(array $filters): LengthAwarePaginator
    {
        return TransferItemLot::query()
            ->select('transfer_item_lots.*')
            ->join('transfer_items', 'transfer_items.id', '=', 'transfer_item_lots.transfer_item_id')
            ->join('transfers', 'transfers.id', '=', 'transfer_items.transfer_id')
            ->where('transfers.status', TransferStatus::EnTransito->value)
            ->whereNull('transfer_item_lots.quantity_received')
            ->with(['lot', 'product', 'transferItem.transfer.originWarehouse', 'transferItem.transfer.destinationWarehouse'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where(
                fn ($w) => $w->where('transfers.origin_warehouse_id', $id)->orWhere('transfers.destination_warehouse_id', $id),
            ))
            ->when($filters['origin_warehouse_id'] ?? null, fn ($q, $id) => $q->where('transfers.origin_warehouse_id', $id))
            ->when($filters['destination_warehouse_id'] ?? null, fn ($q, $id) => $q->where('transfers.destination_warehouse_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('transfer_item_lots.product_id', $id))
            ->when($filters['lot_id'] ?? null, fn ($q, $id) => $q->where('transfer_item_lots.lot_id', $id))
            ->orderBy('transfers.dispatched_at')
            ->orderBy('transfers.id')
            ->orderBy('transfer_item_lots.product_id')
            ->orderBy('transfer_item_lots.lot_id')
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
