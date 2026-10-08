<?php

namespace App\Domain\Inventory;

use App\Enums\KardexType;
use App\Models\KardexMovement;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * Escribe movimientos de kardex (RN-06). Solo lo llama StockService, justo
 * después de cambiar la fila de stocks que tiene bloqueada, de modo que
 * balance_after es el saldo real del lote en la bodega tras el movimiento.
 *
 * Los movimientos son inmutables (trigger en BD): nunca se editan ni borran.
 */
final class KardexService
{
    public function record(
        Stock $stock,
        KardexType $type,
        int $quantity,
        int $direction,
        User $user,
        ?Model $reference = null,
        ?string $reason = null,
    ): KardexMovement {
        return KardexMovement::query()->create([
            'warehouse_id' => $stock->warehouse_id,
            'product_id' => $stock->product_id,
            'lot_id' => $stock->lot_id,
            'type' => $type,
            'quantity' => $quantity,
            'direction' => $direction,
            'balance_after' => $stock->quantity,
            'reason' => $reason,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'user_id' => $user->id,
            // Lo fija el middleware de correlación (Fase 4); si no hay, queda null.
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }
}
