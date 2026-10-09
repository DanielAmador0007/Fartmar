<?php

namespace App\Domain\Inventory;

use App\Domain\Exceptions\InsufficientStockException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Asignación FEFO (RN-01, RN-02). Función pura: no toca la BD.
 *
 * 1. Descarta lotes vencidos (expires_at < hoy) y sin existencias.
 * 2. Ordena por expires_at ASC y, a igual fecha, por lot_id ASC.
 * 3. Toma de cada lote lo que haga falta hasta completar la cantidad.
 * 4. Si no alcanza, lanza InsufficientStockException con disponible vs pedido.
 *
 * Quien la usa dentro de una transacción (DispenseService) le pasa filas ya
 * bloqueadas con FOR UPDATE (StockService::lockFefoCandidates), así lo que se
 * asigna es exactamente lo que se va a descontar.
 */
final class FefoAllocator
{
    /**
     * @param  iterable<StockCandidate>  $stocks
     * @return list<LotAllocation>
     */
    public function allocate(iterable $stocks, int $requested, CarbonImmutable $today): array
    {
        if ($requested <= 0) {
            throw new InvalidArgumentException('La cantidad a asignar debe ser mayor que cero.');
        }

        // Se comparan fechas "Y-m-d" (sin hora ni zona): un lote que vence hoy
        // todavía sirve (S-14); uno que venció ayer no.
        $todayDate = $today->toDateString();

        $usable = [];
        foreach ($stocks as $stock) {
            $notExpired = $stock->expiresAt->toDateString() >= $todayDate;
            if ($notExpired && $stock->quantity > 0) {
                $usable[] = $stock;
            }
        }

        usort($usable, fn (StockCandidate $a, StockCandidate $b): int => [$a->expiresAt->toDateString(), $a->lotId]
            <=> [$b->expiresAt->toDateString(), $b->lotId]);

        $available = array_sum(array_map(fn (StockCandidate $s): int => $s->quantity, $usable));
        if ($available < $requested) {
            throw InsufficientStockException::forAllocation($requested, $available);
        }

        $allocations = [];
        $pending = $requested;
        foreach ($usable as $stock) {
            if ($pending === 0) {
                break;
            }

            $take = min($pending, $stock->quantity);
            $allocations[] = new LotAllocation($stock->lotId, $stock->lotNumber, $stock->expiresAt, $take);
            $pending -= $take;
        }

        return $allocations;
    }
}
