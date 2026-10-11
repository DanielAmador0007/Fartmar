<?php

namespace App\Domain\Inventory;

use App\Models\KardexMovement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Consulta del kardex (RN-06), solo lectura: del más reciente al más antiguo
 * (created_at DESC y, a igual instante, id DESC).
 *
 * Fechas "desde/hasta" (YYYY-MM-DD) se interpretan como días completos en la
 * zona de negocio (America/Bogota, S-13) y se convierten a UTC, que es como
 * se guarda created_at. "hasta" incluye todo ese día.
 */
final class KardexQuery
{
    /**
     * Documentos que originan movimientos (alias del morph map de
     * AppServiceProvider guardados en kardex_movements.reference_type).
     * Entradas iniciales y ajustes manuales no tienen referencia (null).
     */
    public const REFERENCE_TYPES = ['dispensation', 'transfer', 'transfer_discrepancy'];

    /**
     * reference_type (+ reference_id opcional) filtra los movimientos de un
     * documento: p. ej. reference_type=transfer&reference_id=12 trae la
     * SALIDA_TRASLADO del origen y la ENTRADA_TRASLADO del destino.
     *
     * @param  array{warehouse_id?: int|null, product_id?: int|null, lot_id?: int|null, type?: string|null, reference_type?: string|null, reference_id?: int|null, from?: string|null, to?: string|null, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, KardexMovement>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $timezone = (string) config('fartmar.business_timezone', 'America/Bogota');

        return KardexMovement::query()
            ->with(['warehouse', 'product', 'lot', 'user'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($filters['lot_id'] ?? null, fn ($q, $id) => $q->where('lot_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['reference_type'] ?? null, fn ($q, $type) => $q->where('reference_type', $type))
            ->when($filters['reference_id'] ?? null, fn ($q, $id) => $q->where('reference_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where(
                'created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
            ))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where(
                'created_at', '<', CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay()->utc(),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);
    }
}
