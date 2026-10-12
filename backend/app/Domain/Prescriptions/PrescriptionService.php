<?php

namespace App\Domain\Prescriptions;

use App\Domain\Audit\AuditLogger;
use App\Enums\PrescriptionStatus;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea prescripciones (rol médico, PrescriptionPolicy). Nacen ACTIVAS con
 * quantity_dispensed = 0; la vigencia y las cantidades se validan en
 * StorePrescriptionRequest (S-70) y la BD exige valid_until >= issued_at,
 * cantidades > 0 y un producto una sola vez por prescripción.
 */
final class PrescriptionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{product_id: int, quantity: int, instructions?: string|null}>  $items
     */
    public function create(User $prescriber, int $patientId, CarbonImmutable $validUntil, array $items): Prescription
    {
        return DB::transaction(function () use ($prescriber, $patientId, $validUntil, $items): Prescription {
            $prescription = Prescription::query()->create([
                // "RX-" + ULID: único y ordenable por tiempo, sin consultar un consecutivo.
                'number' => 'RX-'.Str::upper((string) Str::ulid()),
                'patient_id' => $patientId,
                'prescriber_id' => $prescriber->id,
                'issued_at' => now(),
                'valid_until' => $validUntil->toDateString(),
                'status' => PrescriptionStatus::Activa,
            ]);

            foreach ($items as $item) {
                PrescriptionItem::query()->create([
                    'prescription_id' => $prescription->id,
                    'product_id' => $item['product_id'],
                    'quantity_prescribed' => $item['quantity'],
                    'quantity_dispensed' => 0,
                    'instructions' => $item['instructions'] ?? null,
                ]);
            }

            // Solo IDs y conteos; las indicaciones son texto libre y no se auditan.
            $this->audit->record($prescriber, 'prescription.created', $prescription, [
                'patient_id' => $patientId,
                'items' => count($items),
                'valid_until' => $validUntil->toDateString(),
            ]);

            return $prescription;
        });
    }
}
