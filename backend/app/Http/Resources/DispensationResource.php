<?php

namespace App\Http\Resources;

use App\Models\Dispensation;
use App\Models\DispensationItem;
use App\Models\DispensationItemLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dispensación con sus líneas y lotes FEFO.
 *
 * Privacidad (RN-10): del paciente solo se expone el ID; nombre y documento
 * se consultan por el endpoint de pacientes, que enmascara según el rol y
 * registra el acceso (Fase 4).
 *
 * @mixin Dispensation
 */
class DispensationResource extends JsonResource
{
    /** Relaciones que el controlador debe cargar antes de responder. */
    public const RELATIONS = [
        'prescription',
        'warehouse',
        'items.product',
        'items.lots.lot',
        'creator',
        'authorizer',
        'rejecter',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'requires_authorization' => $this->requires_authorization,
            'patient_id' => $this->patient_id,
            'prescription' => $this->whenLoaded('prescription', fn () => [
                'id' => $this->prescription->id,
                'number' => $this->prescription->number,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (DispensationItem $item) => [
                'id' => $item->id,
                'prescription_item_id' => $item->prescription_item_id,
                'product' => [
                    'id' => $item->product->id,
                    'code' => $item->product->code,
                    'name' => $item->product->name,
                    'is_controlled' => $item->product->is_controlled,
                ],
                'quantity' => $item->quantity,
                'lots' => $item->lots->map(fn (DispensationItemLot $lot) => [
                    'lot_id' => $lot->lot_id,
                    'lot_number' => $lot->lot->lot_number,
                    'expires_at' => $lot->lot->expires_at->toDateString(),
                    'quantity' => $lot->quantity,
                ])->values(),
            ])->values()),
            'created_by' => new UserSummaryResource($this->whenLoaded('creator')),
            'authorized_by' => $this->authorized_by ? new UserSummaryResource($this->whenLoaded('authorizer')) : null,
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by ? new UserSummaryResource($this->whenLoaded('rejecter')) : null,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
