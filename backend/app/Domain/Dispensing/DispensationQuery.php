<?php

namespace App\Domain\Dispensing;

use App\Models\Dispensation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Listado de dispensaciones (solo lectura), de la más reciente a la más
 * antigua. Sirve, por ejemplo, para la bandeja de controlados pendientes de
 * autorización (status=PENDIENTE_AUTORIZACION).
 */
final class DispensationQuery
{
    /** Relaciones del listado (eager loading: una consulta por relación). */
    public const LIST_RELATIONS = [
        'prescription',
        'warehouse',
        'items.product',
        'creator',
        'authorizer',
        'rejecter',
    ];

    /**
     * @param  array{status?: string|null, warehouse_id?: int|null, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Dispensation>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        return Dispensation::query()
            ->with(self::LIST_RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);
    }
}
