<?php

namespace App\Http\Resources;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prescripción con sus líneas y cantidades pendientes (ver PrescriptionQuery).
 * Del paciente solo va el ID: los datos personales salen por PatientResource.
 *
 * @mixin Prescription
 */
class PrescriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'patient_id' => $this->patient_id,
            'status' => $this->status->value,
            'is_current' => $this->isCurrent(),
            'issued_at' => $this->issued_at->toIso8601String(),
            'valid_until' => $this->valid_until->toDateString(),
            'prescriber' => new UserSummaryResource($this->whenLoaded('prescriber')),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function (PrescriptionItem $item): array {
                $reserved = (int) ($item->quantity_reserved ?? 0);

                return [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'code' => $item->product->code,
                        'name' => $item->product->name,
                        'is_controlled' => $item->product->is_controlled,
                    ],
                    'quantity_prescribed' => $item->quantity_prescribed,
                    'quantity_dispensed' => $item->quantity_dispensed,
                    'quantity_reserved' => $reserved,
                    'quantity_available' => max(0, $item->remainingQuantity() - $reserved),
                    'instructions' => $item->instructions,
                ];
            })->values()),
        ];
    }
}
