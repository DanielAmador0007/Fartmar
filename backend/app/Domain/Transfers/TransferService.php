<?php

namespace App\Domain\Transfers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\InvalidTransitionException;
use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Domain\Exceptions\TransferNotValidException;
use App\Domain\Inventory\BusinessDate;
use App\Domain\Inventory\FefoAllocator;
use App\Domain\Inventory\StockService;
use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\KardexType;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Models\TransferDiscrepancy;
use App\Models\TransferItem;
use App\Models\TransferItemLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Traslados entre bodegas (RN-01, RN-03, RN-06, RN-07, RN-08).
 *
 * Cada operación es UNA transacción (DB::transaction(..., 3): ante deadlock
 * o fallo de serialización Laravel la repite hasta 3 veces) y sigue el mismo
 * patrón:
 *   1. Bloquear la fila del traslado (SELECT ... FOR UPDATE). Dos usuarios
 *      que despachan/aprueban/reciben el MISMO traslado se ponen en fila aquí.
 *   2. Con el bloqueo tomado, leer el estado y validarlo con la máquina de
 *      estados: el segundo en la fila ve el estado ya cambiado y recibe 409
 *      TRANSICION_INVALIDA.
 *   3. Mover stock (solo vía StockService, que escribe el kardex) y guardar.
 *   4. Registrar la transición en audit_logs (quién, cuándo, de qué a qué).
 *
 * Orden de bloqueo (el mismo que en dispensación, evita deadlocks):
 *   cabecera del traslado -> líneas / lotes del traslado por id
 *   -> existencias por product_id y luego (expires_at, lot_id).
 * Una transacción de traslado solo toca existencias de UNA bodega (origen al
 * despachar o al reintegrar, destino al recibir).
 */
final class TransferService
{
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly TransferStateMachine $states,
        private readonly StockService $stocks,
        private readonly FefoAllocator $fefo,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crea el traslado en BORRADOR. No mueve stock ni reserva lotes: los
     * lotes se eligen al despachar (S-20).
     */
    public function create(User $user, TransferData $data): Transfer
    {
        $this->assertValidDraft($data);

        return DB::transaction(function () use ($user, $data): Transfer {
            $transfer = Transfer::query()->create([
                'origin_warehouse_id' => $data->originWarehouseId,
                'destination_warehouse_id' => $data->destinationWarehouseId,
                'status' => TransferStatus::Borrador,
                'notes' => $data->notes,
                'requested_by' => $user->id,
            ]);

            foreach ($data->items as $item) {
                TransferItem::query()->create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $item->productId,
                    'quantity_requested' => $item->quantity,
                ]);
            }

            $this->audit->record($user, 'transfer.created', $transfer, [
                'to' => TransferStatus::Borrador->value,
                'origin_warehouse_id' => $data->originWarehouseId,
                'destination_warehouse_id' => $data->destinationWarehouseId,
                'items' => count($data->items),
            ]);

            return $transfer;
        }, self::ATTEMPTS);
    }

    /**
     * BORRADOR -> SOLICITADO. Solo quien creó el borrador (S-49).
     */
    public function submit(Transfer $transfer, User $user): Transfer
    {
        return DB::transaction(function () use ($transfer, $user): Transfer {
            $locked = $this->lockForTransition($transfer, TransferStatus::Solicitado);

            if ($user->id !== $locked->requested_by) {
                throw SegregationOfDutiesException::notRequester('solicitarlo');
            }

            return $this->moveTo($locked, TransferStatus::Solicitado, $user, 'transfer.submitted', [
                'requested_at' => now(),
            ]);
        }, self::ATTEMPTS);
    }

    /**
     * SOLICITADO -> APROBADO. RN-08: un regente activo distinto de quien
     * solicitó. La BD lo vuelve a exigir (CHECK approved_by <> requested_by
     * y trigger de rol).
     */
    public function approve(Transfer $transfer, User $regente): Transfer
    {
        return DB::transaction(function () use ($transfer, $regente): Transfer {
            $locked = $this->lockForTransition($transfer, TransferStatus::Aprobado);
            $this->assertActiveRegente($regente, 'aprobar el traslado');

            if ($regente->id === $locked->requested_by) {
                throw SegregationOfDutiesException::sameUser('aprobar el traslado');
            }

            return $this->moveTo($locked, TransferStatus::Aprobado, $regente, 'transfer.approved', [
                'approved_by' => $regente->id,
                'approved_at' => now(),
            ]);
        }, self::ATTEMPTS);
    }

    /**
     * APROBADO -> EN_TRANSITO. Por cada producto (en orden de product_id):
     * bloquea las existencias NO vencidas de la bodega origen en orden FEFO,
     * asigna lotes (puede ser más de uno), descuenta con kardex
     * SALIDA_TRASLADO y guarda los lotes en transfer_item_lots.
     *
     * Si un producto no alcanza, InsufficientStockException deshace TODA la
     * transacción: no sale nada de ningún producto y el traslado sigue APROBADO.
     */
    public function dispatch(Transfer $transfer, User $user): Transfer
    {
        return DB::transaction(function () use ($transfer, $user): Transfer {
            $locked = $this->lockForTransition($transfer, TransferStatus::EnTransito);
            $today = BusinessDate::today();
            $units = 0;

            // Las líneas no se modifican: no hace falta bloquearlas (la
            // cabecera ya serializa). Orden por producto = orden de bloqueo.
            $items = $locked->items()->orderBy('product_id')->get();

            foreach ($items as $item) {
                $candidates = $this->stocks->lockFefoCandidates($locked->origin_warehouse_id, $item->product_id, $today);

                try {
                    $allocations = $this->fefo->allocate($candidates, $item->quantity_requested, $today);
                } catch (InsufficientStockException $e) {
                    throw $e->withContext($locked->origin_warehouse_id, $item->product_id);
                }

                foreach ($allocations as $allocation) {
                    $this->stocks->decrease(
                        $locked->origin_warehouse_id,
                        $allocation->lotId,
                        $allocation->quantity,
                        KardexType::SalidaTraslado,
                        $user,
                        $locked,
                    );

                    TransferItemLot::query()->create([
                        'transfer_item_id' => $item->id,
                        'lot_id' => $allocation->lotId,
                        'product_id' => $item->product_id,
                        'quantity_dispatched' => $allocation->quantity,
                    ]);

                    $units += $allocation->quantity;
                }
            }

            return $this->moveTo($locked, TransferStatus::EnTransito, $user, 'transfer.dispatched', [
                'dispatched_by' => $user->id,
                'dispatched_at' => now(),
            ], ['units' => $units]);
        }, self::ATTEMPTS);
    }

    /**
     * EN_TRANSITO -> RECIBIDO | RECIBIDO_PARCIAL.
     *
     * $received trae, por cada lote despachado (transfer_item_lot_id), las
     * unidades que llegaron (0..despachado). Todos los lotes despachados deben
     * venir. Lo recibido entra a la bodega destino (ENTRADA_TRASLADO); lo que
     * falta queda como discrepancia PENDIENTE por lote.
     *
     * Un lote que venció mientras viajaba SÍ se recibe (S-48): la recepción
     * registra la existencia física; RN-01 impide dispensarlo o trasladarlo
     * de nuevo, y se da de baja con un AJUSTE negativo (S-35).
     *
     * @param  array<int, int>  $received  transfer_item_lot_id => cantidad recibida
     */
    public function receive(Transfer $transfer, User $user, array $received): Transfer
    {
        return DB::transaction(function () use ($transfer, $user, $received): Transfer {
            // RECIBIDO y RECIBIDO_PARCIAL salen ambos solo de EN_TRANSITO:
            // se valida aquí con RECIBIDO y abajo con el estado final real.
            $locked = $this->lockForTransition($transfer, TransferStatus::Recibido);

            $lots = TransferItemLot::query()
                ->whereIn('transfer_item_id', $locked->items()->select('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertReceiptMatches($locked, $lots->pluck('quantity_dispatched', 'id')->all(), $received);

            // Orden de bloqueo de existencias: producto, vencimiento, lote.
            $lots->load('lot');
            $ordered = $lots->all();
            usort($ordered, fn (TransferItemLot $a, TransferItemLot $b): int => [$a->product_id, $a->lot->expires_at->toDateString(), $a->lot_id]
                <=> [$b->product_id, $b->lot->expires_at->toDateString(), $b->lot_id]);

            $missingTotal = 0;
            foreach ($ordered as $itemLot) {
                $quantity = $received[$itemLot->id];
                $itemLot->update(['quantity_received' => $quantity]);

                if ($quantity > 0) {
                    $this->stocks->increase(
                        $locked->destination_warehouse_id,
                        $itemLot->lot,
                        $quantity,
                        KardexType::EntradaTraslado,
                        $user,
                        $locked,
                    );
                }

                $missing = $itemLot->quantity_dispatched - $quantity;
                if ($missing > 0) {
                    TransferDiscrepancy::query()->create([
                        'transfer_item_lot_id' => $itemLot->id,
                        'quantity_missing' => $missing,
                        'status' => DiscrepancyStatus::Pendiente,
                    ]);
                    $missingTotal += $missing;
                }
            }

            $target = $missingTotal === 0 ? TransferStatus::Recibido : TransferStatus::RecibidoParcial;
            $this->states->assertCanTransition($locked->status, $target, $locked->id);

            return $this->moveTo($locked, $target, $user, 'transfer.received', [
                'received_by' => $user->id,
                'received_at' => now(),
            ], ['units_received' => array_sum($received), 'units_missing' => $missingTotal]);
        }, self::ATTEMPTS);
    }

    /**
     * BORRADOR | SOLICITADO | APROBADO -> ANULADO, con motivo. Nunca después
     * de despachar (RN-07): lo despachado ya movió stock y se cierra
     * recibiendo (con discrepancias si hace falta).
     */
    public function cancel(Transfer $transfer, User $user, string $reason): Transfer
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw TransferNotValidException::reasonRequired('anular el traslado');
        }

        return DB::transaction(function () use ($transfer, $user, $reason): Transfer {
            $locked = $this->lockForTransition($transfer, TransferStatus::Anulado);

            return $this->moveTo($locked, TransferStatus::Anulado, $user, 'transfer.cancelled', [
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
        }, self::ATTEMPTS);
    }

    /**
     * Un regente cierra una discrepancia PENDIENTE (S-50):
     * - REINTEGRAR_ORIGEN: lo faltante vuelve a la bodega origen con un
     *   AJUSTE (+) cuyo motivo cita la discrepancia y el traslado.
     * - PERDIDA: solo se registra; el stock ya salió del origen al despachar.
     */
    public function resolveDiscrepancy(
        TransferDiscrepancy $discrepancy,
        User $regente,
        DiscrepancyResolution $action,
        string $resolution,
    ): TransferDiscrepancy {
        $resolution = trim($resolution);
        if ($resolution === '') {
            throw TransferNotValidException::reasonRequired('resolver la discrepancia');
        }

        return DB::transaction(function () use ($discrepancy, $regente, $action, $resolution): TransferDiscrepancy {
            // Datos que no cambian (a qué lote y traslado pertenece): sin bloqueo.
            $itemLot = TransferItemLot::query()->with(['lot', 'transferItem'])->findOrFail($discrepancy->transfer_item_lot_id);

            // Mismo orden que el resto: cabecera del traslado primero.
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($itemLot->transferItem->transfer_id);
            $locked = TransferDiscrepancy::query()->lockForUpdate()->findOrFail($discrepancy->id);

            if ($locked->status !== DiscrepancyStatus::Pendiente) {
                throw InvalidTransitionException::between('transfer_discrepancy', $locked->id, $locked->status->value, DiscrepancyStatus::Resuelta->value);
            }

            $this->assertActiveRegente($regente, 'resolver discrepancias de traslado');

            if ($action === DiscrepancyResolution::ReintegrarOrigen) {
                $this->stocks->increase(
                    $transfer->origin_warehouse_id,
                    $itemLot->lot,
                    $locked->quantity_missing,
                    KardexType::Ajuste,
                    $regente,
                    $locked,
                    "Reintegro al origen por la discrepancia #{$locked->id} del traslado #{$transfer->id}: {$resolution}",
                );
            }

            $locked->update([
                'status' => DiscrepancyStatus::Resuelta,
                'resolution_action' => $action,
                'resolution' => $resolution,
                'resolved_by' => $regente->id,
                'resolved_at' => now(),
            ]);

            $this->audit->record($regente, 'transfer_discrepancy.resolved', $locked, [
                'transfer_id' => $transfer->id,
                'action' => $action->value,
                'quantity_missing' => $locked->quantity_missing,
            ]);

            return $locked;
        }, self::ATTEMPTS);
    }

    /**
     * Bloquea la cabecera y valida la transición con el estado YA bloqueado.
     */
    private function lockForTransition(Transfer $transfer, TransferStatus $to): Transfer
    {
        $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
        $this->states->assertCanTransition($locked->status, $to, $locked->id);

        return $locked;
    }

    /**
     * Cambia el estado (con su actor y fecha) y lo deja en audit_logs.
     *
     * @param  array<string, mixed>  $columns  actor / fecha / motivo del nuevo estado
     * @param  array<string, mixed>  $metadata  solo IDs y cantidades (sin texto libre)
     */
    private function moveTo(Transfer $transfer, TransferStatus $to, User $user, string $action, array $columns, array $metadata = []): Transfer
    {
        $from = $transfer->status;
        $transfer->update(['status' => $to] + $columns);

        $this->audit->record($user, $action, $transfer, ['from' => $from->value, 'to' => $to->value] + $metadata);

        return $transfer;
    }

    private function assertValidDraft(TransferData $data): void
    {
        if ($data->originWarehouseId === $data->destinationWarehouseId) {
            throw TransferNotValidException::sameWarehouse($data->originWarehouseId);
        }

        if ($data->items === []) {
            throw TransferNotValidException::noItems();
        }

        $productIds = $data->productIds();
        $repeated = array_keys(array_filter(array_count_values($productIds), fn (int $count) => $count > 1));
        if ($repeated !== []) {
            throw TransferNotValidException::duplicatedProducts($repeated);
        }
    }

    /**
     * La recepción debe traer exactamente los lotes despachados, cada uno
     * con 0..despachado unidades (la BD también lo exige con un CHECK).
     *
     * @param  array<int, int>  $dispatched  transfer_item_lot_id => despachado
     * @param  array<int, int>  $received  transfer_item_lot_id => recibido
     */
    private function assertReceiptMatches(Transfer $transfer, array $dispatched, array $received): void
    {
        $missing = array_values(array_diff(array_keys($dispatched), array_keys($received)));
        $unknown = array_values(array_diff(array_keys($received), array_keys($dispatched)));

        if ($missing !== [] || $unknown !== []) {
            throw TransferNotValidException::receiptDoesNotMatch($transfer->id, $missing, $unknown);
        }

        foreach ($dispatched as $id => $quantity) {
            if ($received[$id] < 0 || $received[$id] > $quantity) {
                throw TransferNotValidException::receivedOutOfRange($id, $received[$id], $quantity);
            }
        }
    }

    private function assertActiveRegente(User $user, string $action): void
    {
        if (! $user->hasRole(Role::RegenteFarmacia) || ! $user->is_active) {
            throw SegregationOfDutiesException::roleRequired($action, Role::RegenteFarmacia->value);
        }
    }
}
