<?php

namespace App\Domain\Dispensing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\InvalidTransitionException;
use App\Domain\Exceptions\PrescriptionExceededException;
use App\Domain\Exceptions\PrescriptionNotValidException;
use App\Domain\Exceptions\SegregationOfDutiesException;
use App\Domain\Inventory\BusinessDate;
use App\Domain\Inventory\FefoAllocator;
use App\Domain\Inventory\LotAllocation;
use App\Domain\Inventory\StockService;
use App\Enums\DispensationStatus;
use App\Enums\KardexType;
use App\Enums\PrescriptionStatus;
use App\Enums\Role;
use App\Models\Dispensation;
use App\Models\DispensationItem;
use App\Models\DispensationItemLot;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Dispensación de medicamentos (RN-01 a RN-06, RN-09).
 *
 * Orden de bloqueo, igual en todos los caminos (evita deadlocks):
 *   dispensación -> prescripción -> líneas de prescripción (por id)
 *   -> existencias (por product_id y luego expires_at, lot_id).
 *
 * DB::transaction(..., 3): si PostgreSQL detecta un deadlock o un fallo de
 * serialización, Laravel reintenta la transacción completa hasta 3 veces.
 */
final class DispenseService
{
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly StockService $stocks,
        private readonly FefoAllocator $fefo,
        private readonly IdempotencyGuard $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crea una dispensación. Si algún producto es de control especial queda
     * PENDIENTE_AUTORIZACION sin mover stock (RN-05); si no, se completa.
     */
    public function create(User $user, DispenseData $data, string $idempotencyKey): DispenseResult
    {
        $requestHash = $this->idempotency->hash($user->id, $data);

        try {
            return DB::transaction(
                fn () => $this->createInTransaction($user, $data, $idempotencyKey, $requestHash),
                self::ATTEMPTS,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Dos reintentos con la misma clave llegaron a la vez: el segundo
            // esperó en el índice UNIQUE hasta que el primero confirmó, y su
            // transacción ya se deshizo. Se devuelve la dispensación ganadora.
            if (! $this->idempotency->isKeyCollision($e)) {
                throw $e;
            }

            $existing = $this->idempotency->findReplay($idempotencyKey, $requestHash) ?? throw $e;

            return new DispenseResult($existing, true);
        }
    }

    /**
     * Un regente distinto del creador autoriza un controlado: aquí se
     * asignan los lotes (FEFO) y sale el stock (RN-05).
     */
    public function authorize(Dispensation $dispensation, User $regente): Dispensation
    {
        return DB::transaction(function () use ($dispensation, $regente): Dispensation {
            $locked = $this->lockPending($dispensation, DispensationStatus::Completada);
            $this->assertSecondRegente($locked, $regente, 'autorizar la dispensación');

            $quantities = $locked->items()->pluck('quantity', 'prescription_item_id')->map(fn ($q) => (int) $q)->all();
            $lines = $this->lockAndValidatePrescription($locked, $quantities);

            $this->executeOutflow($locked, $lines, $regente);

            $locked->update([
                'status' => DispensationStatus::Completada,
                'authorized_by' => $regente->id,
                'authorized_at' => now(),
            ]);

            $this->audit->record($regente, 'dispensation.authorized', $locked, [
                'prescription_id' => $locked->prescription_id,
                'warehouse_id' => $locked->warehouse_id,
            ]);

            return $locked;
        }, self::ATTEMPTS);
    }

    /**
     * Un regente distinto del creador rechaza un controlado pendiente. No se
     * mueve stock y la cantidad reservada vuelve a quedar disponible.
     */
    public function reject(Dispensation $dispensation, User $regente, string $reason): Dispensation
    {
        return DB::transaction(function () use ($dispensation, $regente, $reason): Dispensation {
            $locked = $this->lockPending($dispensation, DispensationStatus::Rechazada);
            $this->assertSecondRegente($locked, $regente, 'rechazar la dispensación');

            $locked->update([
                'status' => DispensationStatus::Rechazada,
                'rejected_by' => $regente->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->record($regente, 'dispensation.rejected', $locked, [
                'prescription_id' => $locked->prescription_id,
            ]);

            return $locked;
        }, self::ATTEMPTS);
    }

    /**
     * Vista previa FEFO: qué lotes saldrían hoy, SIN bloquear ni mover stock.
     */
    public function preview(int $warehouseId, Product $product, int $quantity): FefoPreview
    {
        $today = BusinessDate::today();
        $candidates = $this->stocks->fefoCandidates($warehouseId, $product->id, $today);

        try {
            $allocations = $this->fefo->allocate($candidates, $quantity, $today);
        } catch (InsufficientStockException $e) {
            throw $e->withContext($warehouseId, $product->id);
        }

        return new FefoPreview($warehouseId, $product, $quantity, $allocations);
    }

    private function createInTransaction(User $user, DispenseData $data, string $idempotencyKey, string $requestHash): DispenseResult
    {
        // 1. Reintento con la misma clave: devolver la original (o 409 si el contenido cambió).
        $existing = $this->idempotency->findReplay($idempotencyKey, $requestHash);
        if ($existing !== null) {
            return new DispenseResult($existing, true);
        }

        // 2. Datos que no cambian (paciente de la prescripción, producto de
        //    cada línea): se leen sin bloqueo para armar la cabecera.
        $prescription = Prescription::query()->findOrFail($data->prescriptionId);
        if ($prescription->patient_id !== $data->patientId) {
            throw PrescriptionNotValidException::patientMismatch($prescription->id);
        }

        $lines = PrescriptionItem::query()
            ->with('product')
            ->where('prescription_id', $prescription->id)
            ->whereIn('id', $data->prescriptionItemIds())
            ->get()
            ->keyBy('id');

        $unknown = array_values(array_diff($data->prescriptionItemIds(), $lines->keys()->all()));
        if ($unknown !== []) {
            throw PrescriptionNotValidException::itemsNotInPrescription($prescription->id, $unknown);
        }

        $requiresAuthorization = $lines->contains(fn (PrescriptionItem $line) => $line->product->is_controlled);

        // 3. Reclamar la Idempotency-Key ANTES de validar cantidades: si otro
        //    reintento con la misma clave está en curso, este INSERT espera en
        //    el índice UNIQUE y falla al confirmar el otro (ver create()).
        //    requires_authorization debe ir antes de las líneas (trigger RN-05).
        $dispensation = Dispensation::query()->create([
            'patient_id' => $data->patientId,
            'prescription_id' => $prescription->id,
            'warehouse_id' => $data->warehouseId,
            'status' => $requiresAuthorization ? DispensationStatus::PendienteAutorizacion : DispensationStatus::Completada,
            'requires_authorization' => $requiresAuthorization,
            'created_by' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'correlation_id' => Context::get('correlation_id'),
        ]);

        // 4. Bloquear prescripción y líneas; validar vigencia y saldo (RN-04).
        $quantities = [];
        foreach ($data->items as $item) {
            $quantities[$item->prescriptionItemId] = $item->quantity;
        }
        $lockedLines = $this->lockAndValidatePrescription($dispensation, $quantities);

        foreach ($lockedLines as $line) {
            DispensationItem::query()->create([
                'dispensation_id' => $dispensation->id,
                'prescription_item_id' => $line->id,
                'product_id' => $line->product_id,
                'quantity' => $quantities[$line->id],
            ]);
        }

        // 5. Controlado: queda pendiente sin tocar stock. Si no: FEFO y salida.
        if (! $requiresAuthorization) {
            $this->executeOutflow($dispensation, $lockedLines, $user);
        }

        $this->audit->record($user, 'dispensation.created', $dispensation, [
            'status' => $dispensation->status->value,
            'prescription_id' => $prescription->id,
            'warehouse_id' => $data->warehouseId,
        ]);

        return new DispenseResult($dispensation, false);
    }

    /**
     * Bloquea la prescripción y las líneas pedidas (en orden de id) y valida:
     * vigencia (S-21) y que lo pedido no supere lo prescrito menos lo ya
     * entregado y lo reservado por otras dispensaciones pendientes (RN-04).
     *
     * @param  array<int, int>  $quantities  prescription_item_id => cantidad
     * @return Collection<int, PrescriptionItem>
     */
    private function lockAndValidatePrescription(Dispensation $dispensation, array $quantities): Collection
    {
        $prescription = Prescription::query()->lockForUpdate()->findOrFail($dispensation->prescription_id);
        $this->assertPrescriptionValid($prescription);

        $lines = PrescriptionItem::query()
            ->where('prescription_id', $prescription->id)
            ->whereIn('id', array_keys($quantities))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $pending = $this->pendingQuantities(array_keys($quantities), $dispensation->id);

        foreach ($lines as $line) {
            $requested = $quantities[$line->id];
            $reserved = $pending[$line->id] ?? 0;

            if ($requested > $line->quantity_prescribed - $line->quantity_dispensed - $reserved) {
                throw PrescriptionExceededException::forItem(
                    $line->id, $requested, $line->quantity_prescribed, $line->quantity_dispensed, $reserved,
                );
            }
        }

        return $lines;
    }

    private function assertPrescriptionValid(Prescription $prescription): void
    {
        if ($prescription->status !== PrescriptionStatus::Activa) {
            throw PrescriptionNotValidException::withStatus($prescription->id, $prescription->status->value);
        }

        if ($prescription->valid_until->toDateString() < BusinessDate::today()->toDateString()) {
            throw PrescriptionNotValidException::expired($prescription->id, $prescription->valid_until);
        }
    }

    /**
     * Unidades reservadas por OTRAS dispensaciones PENDIENTE_AUTORIZACION.
     *
     * @param  list<int>  $prescriptionItemIds
     * @return array<int, int> prescription_item_id => cantidad
     */
    private function pendingQuantities(array $prescriptionItemIds, int $excludeDispensationId): array
    {
        return DispensationItem::query()
            ->join('dispensations', 'dispensations.id', '=', 'dispensation_items.dispensation_id')
            ->where('dispensations.status', DispensationStatus::PendienteAutorizacion->value)
            ->where('dispensations.id', '<>', $excludeDispensationId)
            ->whereIn('dispensation_items.prescription_item_id', $prescriptionItemIds)
            ->groupBy('dispensation_items.prescription_item_id')
            ->selectRaw('dispensation_items.prescription_item_id, SUM(dispensation_items.quantity) AS reserved')
            ->pluck('reserved', 'prescription_item_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /**
     * Salida de stock FEFO por cada línea (RN-02, RN-03, RN-06): bloquea las
     * existencias del producto, asigna lotes, descuenta (kardex
     * SALIDA_DISPENSACION), guarda los lotes usados y acumula lo dispensado.
     *
     * @param  Collection<int, PrescriptionItem>  $lines  líneas ya bloqueadas
     */
    private function executeOutflow(Dispensation $dispensation, Collection $lines, User $actor): void
    {
        $today = BusinessDate::today();
        $linesById = $lines->keyBy('id');

        // Orden de bloqueo consistente entre transacciones: por producto.
        $items = $dispensation->items()->orderBy('product_id')->get();

        foreach ($items as $item) {
            $candidates = $this->stocks->lockFefoCandidates($dispensation->warehouse_id, $item->product_id, $today);

            try {
                $allocations = $this->fefo->allocate($candidates, $item->quantity, $today);
            } catch (InsufficientStockException $e) {
                throw $e->withContext($dispensation->warehouse_id, $item->product_id);
            }

            foreach ($allocations as $allocation) {
                $this->dispenseLot($dispensation, $item, $allocation, $actor);
            }

            // UPDATE relativo (quantity_dispensed = quantity_dispensed + n),
            // igual que StockService::applyDelta: si algún camino llegara sin
            // el FOR UPDATE de la línea, PostgreSQL suma sobre el valor
            // confirmado y el CHECK (dispensed <= prescribed) rechaza el
            // exceso, en vez de sobrescribir en silencio lo que otro entregó.
            $line = $linesById->get($item->prescription_item_id) ?? throw new LogicException('Línea de prescripción no bloqueada.');
            $line->increment('quantity_dispensed', $item->quantity);
        }

        $this->completePrescriptionIfFullyDispensed($dispensation->prescription_id);
    }

    private function dispenseLot(Dispensation $dispensation, DispensationItem $item, LotAllocation $allocation, User $actor): void
    {
        $this->stocks->decrease(
            $dispensation->warehouse_id,
            $allocation->lotId,
            $allocation->quantity,
            KardexType::SalidaDispensacion,
            $actor,
            $dispensation,
        );

        DispensationItemLot::query()->create([
            'dispensation_item_id' => $item->id,
            'lot_id' => $allocation->lotId,
            'product_id' => $item->product_id,
            'quantity' => $allocation->quantity,
        ]);
    }

    private function completePrescriptionIfFullyDispensed(int $prescriptionId): void
    {
        $hasRemaining = PrescriptionItem::query()
            ->where('prescription_id', $prescriptionId)
            ->whereColumn('quantity_dispensed', '<', 'quantity_prescribed')
            ->exists();

        if (! $hasRemaining) {
            Prescription::query()->whereKey($prescriptionId)->update(['status' => PrescriptionStatus::Completada]);
        }
    }

    /**
     * Bloquea la dispensación y exige que siga pendiente: dos regentes que
     * autorizan a la vez se serializan aquí y el segundo recibe 409.
     */
    private function lockPending(Dispensation $dispensation, DispensationStatus $target): Dispensation
    {
        $locked = Dispensation::query()->lockForUpdate()->findOrFail($dispensation->id);

        if ($locked->status !== DispensationStatus::PendienteAutorizacion) {
            throw InvalidTransitionException::between('dispensation', $locked->id, $locked->status->value, $target->value);
        }

        return $locked;
    }

    /**
     * RN-05: regente de farmacia activo y distinto de quien creó la
     * dispensación. La Policy ya lo filtra; aquí se repite porque es la regla
     * de negocio (y la BD lo vuelve a exigir con CHECK y trigger).
     */
    private function assertSecondRegente(Dispensation $dispensation, User $regente, string $action): void
    {
        if (! $regente->hasRole(Role::RegenteFarmacia) || ! $regente->is_active) {
            throw SegregationOfDutiesException::roleRequired($action, Role::RegenteFarmacia->value);
        }

        if ($regente->id === $dispensation->created_by) {
            throw SegregationOfDutiesException::sameUser($action);
        }
    }
}
