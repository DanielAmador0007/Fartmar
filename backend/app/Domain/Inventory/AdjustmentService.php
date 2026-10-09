<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exceptions\AdjustmentNotValidException;
use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Enums\KardexType;
use App\Enums\Role;
use App\Models\KardexMovement;
use App\Models\Lot;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes manuales de inventario (RN-06): la única forma de corregir una
 * existencia sin dispensar ni trasladar. Solo un regente de farmacia activo,
 * siempre con motivo (también lo exige un CHECK del kardex).
 *
 * - Cantidad positiva: suma unidades (p. ej. conteo físico mayor). Si la
 *   bodega no tenía ese lote, se crea la existencia.
 * - Cantidad negativa: resta. Puede retirar un lote VENCIDO (baja por
 *   vencimiento, S-35). Nunca deja stock negativo: StockService bloquea la
 *   fila y lanza STOCK_INSUFICIENTE si no hay suficientes (y el CHECK de la
 *   BD es la última defensa).
 *
 * Los movimientos de kardex no se editan ni se borran: corregir un ajuste
 * equivocado es hacer otro ajuste en sentido contrario.
 */
final class AdjustmentService
{
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly StockService $stocks,
        private readonly AuditLogger $audit,
    ) {}

    public function adjust(User $regente, int $warehouseId, int $lotId, int $quantity, string $reason): KardexMovement
    {
        if (! $regente->hasRole(Role::RegenteFarmacia) || ! $regente->is_active) {
            throw SegregationOfDutiesException::roleRequired('ajustar el inventario', Role::RegenteFarmacia->value);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw AdjustmentNotValidException::reasonRequired();
        }

        if ($quantity === 0) {
            throw AdjustmentNotValidException::zeroQuantity();
        }

        return DB::transaction(function () use ($regente, $warehouseId, $lotId, $quantity, $reason): KardexMovement {
            $movement = $quantity > 0
                ? $this->stocks->increase($warehouseId, Lot::query()->findOrFail($lotId), $quantity, KardexType::Ajuste, $regente, null, $reason)
                : $this->stocks->decrease($warehouseId, $lotId, -$quantity, KardexType::Ajuste, $regente, null, $reason);

            $stock = Stock::query()->where('warehouse_id', $warehouseId)->where('lot_id', $lotId)->firstOrFail();

            // Sin el motivo (texto libre): solo IDs y cantidades.
            $this->audit->record($regente, 'inventory.adjusted', $stock, [
                'kardex_movement_id' => $movement->id,
                'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'quantity' => $quantity,
                'balance_after' => $movement->balance_after,
            ]);

            return $movement;
        }, self::ATTEMPTS);
    }
}
