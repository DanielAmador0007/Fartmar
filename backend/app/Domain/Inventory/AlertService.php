<?php

namespace App\Domain\Inventory;

use App\Models\Stock;
use App\Models\StockMinimum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Alertas de inventario (RN-11). Solo lectura.
 *
 * 1. Vencimientos: existencias con unidades (> 0) cuyo lote vence dentro de
 *    los próximos N días (config fartmar.alert_expiry_days, 90 por defecto).
 *    Incluye los lotes YA vencidos que aún tienen unidades (marcados como
 *    vencidos): hay que darlos de baja con un AJUSTE (S-35, S-51).
 * 2. Stock mínimo: por bodega + producto con mínimo definido
 *    (stock_minimums), cuando lo DISPONIBLE (solo lotes no vencidos) es menor
 *    que el mínimo. Incluye productos sin ninguna existencia (disponible 0).
 *
 * Límite (L3, S-62): cada lista devuelve como máximo $limit filas (las más
 * urgentes primero) y count*() dice cuántas hay en total, para que la
 * pantalla avise "mostrando 100 de 340" y el usuario filtre por bodega.
 */
final class AlertService
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 500;

    public function expiryDays(): int
    {
        return (int) config('fartmar.alert_expiry_days', 90);
    }

    /**
     * Ordenadas por fecha de vencimiento (lo más urgente primero).
     *
     * @return list<ExpiringLotAlert>
     */
    public function expiringLots(?int $warehouseId = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $today = BusinessDate::today();

        $stocks = $this->expiringQuery($today, $warehouseId)
            ->with(['warehouse', 'product', 'lot'])
            ->orderBy('lots.expires_at')
            ->orderBy('stocks.warehouse_id')
            ->orderBy('stocks.lot_id')
            ->limit($limit)
            ->get();

        $alerts = [];
        foreach ($stocks as $stock) {
            $alerts[] = new ExpiringLotAlert($stock, $this->daysUntil($today, $stock->lot->expires_at));
        }

        return $alerts;
    }

    public function countExpiringLots(?int $warehouseId = null): int
    {
        return $this->expiringQuery(BusinessDate::today(), $warehouseId)->count();
    }

    /**
     * Ordenadas por bodega y producto.
     *
     * @return list<LowStockAlert>
     */
    public function lowStock(?int $warehouseId = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $rows = $this->lowStockQuery($warehouseId)
            ->with(['warehouse', 'product'])
            ->orderBy('stock_minimums.warehouse_id')
            ->orderBy('stock_minimums.product_id')
            ->limit($limit)
            ->get();

        $alerts = [];
        foreach ($rows as $row) {
            $alerts[] = new LowStockAlert($row->warehouse, $row->product, $row->min_quantity, (int) $row->getAttribute('available'));
        }

        return $alerts;
    }

    public function countLowStock(?int $warehouseId = null): int
    {
        return $this->lowStockQuery($warehouseId)->count();
    }

    /**
     * @return Builder<Stock>
     */
    private function expiringQuery(CarbonImmutable $today, ?int $warehouseId): Builder
    {
        return Stock::query()
            ->select('stocks.*')
            ->join('lots', 'lots.id', '=', 'stocks.lot_id')
            ->where('stocks.quantity', '>', 0)
            ->where('lots.expires_at', '<=', $today->addDays($this->expiryDays())->toDateString())
            ->when($warehouseId, fn ($q, $id) => $q->where('stocks.warehouse_id', $id));
    }

    /**
     * @return Builder<StockMinimum>
     */
    private function lowStockQuery(?int $warehouseId): Builder
    {
        // Disponible por bodega + producto: solo lotes no vencidos (RN-01),
        // porque un vencido no se puede dispensar ni trasladar.
        $available = DB::table('stocks')
            ->join('lots', 'lots.id', '=', 'stocks.lot_id')
            ->where('lots.expires_at', '>=', BusinessDate::today()->toDateString())
            ->groupBy('stocks.warehouse_id', 'stocks.product_id')
            ->select(['stocks.warehouse_id', 'stocks.product_id'])
            ->selectRaw('SUM(stocks.quantity) AS available');

        return StockMinimum::query()
            ->select('stock_minimums.*')
            ->selectRaw('COALESCE(available_stock.available, 0) AS available')
            ->leftJoinSub($available, 'available_stock', fn (JoinClause $join) => $join
                ->on('available_stock.warehouse_id', '=', 'stock_minimums.warehouse_id')
                ->on('available_stock.product_id', '=', 'stock_minimums.product_id'))
            ->whereRaw('COALESCE(available_stock.available, 0) < stock_minimums.min_quantity')
            ->when($warehouseId, fn ($q, $id) => $q->where('stock_minimums.warehouse_id', $id));
    }

    /**
     * Días de calendario de hoy al vencimiento (0 = vence hoy, negativo = vencido).
     */
    private function daysUntil(CarbonImmutable $today, CarbonImmutable $expiresAt): int
    {
        return (int) $today->diffInDays(CarbonImmutable::parse($expiresAt->toDateString(), $today->getTimezone()), false);
    }
}
