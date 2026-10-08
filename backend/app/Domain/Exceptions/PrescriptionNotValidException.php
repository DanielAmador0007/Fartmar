<?php

namespace App\Domain\Exceptions;

use Carbon\CarbonImmutable;

/**
 * RN-04: la prescripción no está vigente (vencida, anulada o ya entregada
 * por completo) o no corresponde al paciente / a las líneas enviadas.
 */
final class PrescriptionNotValidException extends BusinessRuleException
{
    public static function expired(int $prescriptionId, CarbonImmutable $validUntil): self
    {
        return new self(
            "La prescripción venció el {$validUntil->toDateString()}; pida al médico una nueva fórmula.",
            ['prescription_id' => $prescriptionId, 'reason' => 'VENCIDA', 'valid_until' => $validUntil->toDateString()],
        );
    }

    public static function withStatus(int $prescriptionId, string $status): self
    {
        $message = match ($status) {
            'COMPLETADA' => 'La prescripción ya fue entregada en su totalidad.',
            'ANULADA' => 'La prescripción fue anulada y no se puede dispensar.',
            default => 'La prescripción no está vigente.',
        };

        return new self($message, ['prescription_id' => $prescriptionId, 'reason' => $status]);
    }

    public static function patientMismatch(int $prescriptionId): self
    {
        return new self(
            'La prescripción no pertenece al paciente indicado.',
            ['prescription_id' => $prescriptionId, 'reason' => 'PACIENTE_NO_CORRESPONDE'],
        );
    }

    /**
     * @param  list<int>  $prescriptionItemIds
     */
    public static function itemsNotInPrescription(int $prescriptionId, array $prescriptionItemIds): self
    {
        return new self(
            'Algunos medicamentos enviados no hacen parte de la prescripción.',
            ['prescription_id' => $prescriptionId, 'reason' => 'LINEA_NO_CORRESPONDE', 'prescription_item_ids' => $prescriptionItemIds],
        );
    }

    public function errorCode(): string
    {
        return 'PRESCRIPCION_NO_VIGENTE';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
