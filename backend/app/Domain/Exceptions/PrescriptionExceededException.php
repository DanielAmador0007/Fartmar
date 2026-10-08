<?php

namespace App\Domain\Exceptions;

/**
 * RN-04: no se puede dispensar más de lo prescrito (sumando parciales
 * anteriores y dispensaciones pendientes de autorización).
 */
final class PrescriptionExceededException extends BusinessRuleException
{
    public static function forItem(int $prescriptionItemId, int $requested, int $prescribed, int $dispensed, int $pending): self
    {
        $remaining = max(0, $prescribed - $dispensed - $pending);

        return new self(
            "Se pidieron {$requested} unidades pero a la prescripción solo le quedan {$remaining} por entregar (prescritas {$prescribed}, ya entregadas {$dispensed}, pendientes de autorización {$pending}).",
            [
                'prescription_item_id' => $prescriptionItemId,
                'requested' => $requested,
                'quantity_prescribed' => $prescribed,
                'quantity_dispensed' => $dispensed,
                'quantity_pending_authorization' => $pending,
                'remaining' => $remaining,
            ],
        );
    }

    public function errorCode(): string
    {
        return 'PRESCRIPCION_EXCEDIDA';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
