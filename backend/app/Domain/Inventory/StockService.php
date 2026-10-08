<?php

namespace App\Domain\Inventory;

use App\Domain\Exceptions\ExpiredLotException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Enums\KardexType;
use App\Models\KardexMovement;
use App\Models\Lot;
use App\Models\Stock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * ÚNICO punto del sistema que modifica stocks.quantity (RN-03, RN-06).
 *
 * Cada increase()/decrease():
 *   1. Bloquea la fila (bodega + lote) con SELECT ... FOR UPDATE.
 *   2. Valida (existencias suficientes; lote no vencido en salidas).
 *   3. Actualiza la cantidad.
 *   4. Escribe el movimiento de kardex con balance_after = nueva cantidad.
 * Todo dentro de la transacción del llamador: si algo falla después, se
 * deshace el stock y el kardex juntos. Por eso exige una transacción abierta.
 *
 * Orden de bloqueo (evita deadlocks): las filas de un producto se bloquean
 * siempre en orden (expires_at, lot_id) con lockFefoCandidates(), y los
 * productos en orden de product_id (lo garantiza el llamador).
 */
final class StockService
{
    /** Salidas a las que aplica RN-01 (nunca un lote vencido). */
    private const EXPIRY_GUARDED = [KardexType::SalidaDispensacion, KardexType::SalidaTraslado];

    public function __construct(private readonly KardexService $kardex) {}

    /**
     * Existencias disponibles para FEFO (no vencidas, > 0) de un producto en
     * una bodega, BLOQUEADAS con FOR UPDATE en orden (expires_at, lot_id).
     *
     * FOR UPDATE OF stocks: bloquea solo las filas de stocks, no las de lots.
     * Si otra transacción tenía bloqueada una fila, esta espera; al seguir,
     * PostgreSQL vuelve a evaluar "quantity > 0" sobre la versión confirmada.
     *
     * @return list<StockCandidate>
     */
    public function lockFefoCandidates(int $warehouseId, int $productId, CarbonImmutable $today): array
    {
        $this->assertInTransaction();

        return $this->toCandidates(
            $this->fefoQuery($warehouseId, $productId, $today)->lock('for update of stocks')->get()
        );
    }

    /**
     * Igual que lockFefoCandidates() pero SIN bloquear: para vistas previas
     * que no mueven stock. El resultado puede cambiar antes de confirmar.
     *
     * @return list<StockCandidate>
     */
    public function fefoCandidates(int $warehouseId, int $productId, CarbonImmutable $today): array
    {
        return $this->toCandidates($this->fefoQuery($warehouseId, $productId, $today)->get());
    }

    /**
     * Resta unidades de un lote en una bodega y registra la salida.
     */
    public function decrease(
        int $warehouseId,
        int $lotId,
        int $quantity,
        KardexType $type,
        User $user,
        ?Model $reference = null,
        ?string $reason = null,
    ): KardexMovement {
        $this->assertInTransaction();
        $this->assertPositive($quantity);

        $stock = $this->lockRow($warehouseId, $lotId);
        $lot = Lot::query()->findOrFail($lotId);

        if ($stock === null || $stock->quantity < $quantity) {
            throw InsufficientStockException::forProduct($warehouseId, $lot->product_id, $quantity, $stock->quantity ?? 0);
        }

        if (in_array($type, self::EXPIRY_GUARDED, true)
            && $lot->expires_at->toDateString() < BusinessDate::today()->toDateString()) {
            throw ExpiredLotException::forLot($lot->id, $lot->lot_number, $lot->expires_at);
        }

        $stock->quantity -= $quantity;
        $stock->save();

        return $this->kardex->record($stock, $type, $quantity, -1, $user, $reference, $reason);
    }

    /**
     * Suma unidades de un lote en una bodega (crea la existencia si no
     * existía) y registra la entrada.
     */
    public function increase(
        int $warehouseId,
        Lot $lot,
        int $quantity,
        KardexType $type,
        User $user,
        ?Model $reference = null,
        ?string $reason = null,
    ): KardexMovement {
        $this->assertInTransaction();
        $this->assertPositive($quantity);

        // ON CONFLICT DO NOTHING: si dos transacciones crean la misma
        // existencia a la vez, ninguna falla; luego ambas bloquean la fila.
        Stock::query()->insertOrIgnore([
            'warehouse_id' => $warehouseId,
            'lot_id' => $lot->id,
            'product_id' => $lot->product_id,
            'quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stock = $this->lockRow($warehouseId, $lot->id) ?? throw new LogicException('No se pudo crear la existencia.');

        $stock->quantity += $quantity;
        $stock->save();

        return $this->kardex->record($stock, $type, $quantity, 1, $user, $reference, $reason);
    }

    private function lockRow(int $warehouseId, int $lotId): ?Stock
    {
        return Stock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('lot_id', $lotId)
            ->lockForUpdate()
            ->first();
    }

    private function fefoQuery(int $warehouseId, int $productId, CarbonImmutable $today): Builder
    {
        return DB::table('stocks')
            ->join('lots', 'lots.id', '=', 'stocks.lot_id')
            ->where('stocks.warehouse_id', $warehouseId)
            ->where('stocks.product_id', $productId)
            ->where('stocks.quantity', '>', 0)
            ->where('lots.expires_at', '>=', $today->toDateString())
            ->orderBy('lots.expires_at')
            ->orderBy('stocks.lot_id')
            ->select(['stocks.lot_id', 'lots.lot_number', 'lots.expires_at', 'stocks.quantity']);
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<StockCandidate>
     */
    private function toCandidates(iterable $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            /** @var object{lot_id: int|string, lot_number: string, expires_at: string, quantity: int|string} $row */
            $candidates[] = new StockCandidate(
                (int) $row->lot_id,
                $row->lot_number,
                CarbonImmutable::parse($row->expires_at),
                (int) $row->quantity,
            );
        }

        return $candidates;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Los cambios de stock deben ejecutarse dentro de una transacción (DB::transaction).');
        }
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('La cantidad del movimiento debe ser mayor que cero.');
        }
    }
}
