<?php

namespace App\Http\Resources;

use App\Models\Dispensation;
use App\Models\DispensationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila del listado de dispensaciones (S-87).
 *
 * Privacidad (RN-10): igual para todos los roles. Del paciente solo va el ID
 * (sin nombre ni documento), no van los lotes entregados ni el motivo de
 * rechazo (texto libre que podría mencionar al paciente). Por eso el listado
 * no escribe en patient_access_logs; el detalle completo se ve en
 * GET /dispensations/{id}, que sí registra el acceso.
 *
 * @mixin Dispensation
 */
class DispensationSummaryResource extends JsonResource
{
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
            'prescription' => [
                'id' => $this->prescription->id,
                'number' => $this->prescription->number,
            ],
            'warehouse' => new WarehouseSummaryResource($this->warehouse),
            'items' => $this->items->map(fn (DispensationItem $item) => [
                'id' => $item->id,
                'product' => [
                    'id' => $item->product->id,
                    'code' => $item->product->code,
                    'name' => $item->product->name,
                    'is_controlled' => $item->product->is_controlled,
                ],
                'quantity' => $item->quantity,
            ])->values(),
            'created_by' => new UserSummaryResource($this->creator),
            'authorized_by' => $this->authorizer ? new UserSummaryResource($this->authorizer) : null,
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'rejected_by' => $this->rejecter ? new UserSummaryResource($this->rejecter) : null,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
