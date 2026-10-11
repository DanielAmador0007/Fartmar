<?php

namespace App\Http\Resources;

use App\Domain\Transfers\TransferActions;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\TransferItemLot;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Traslado con sus líneas, lotes despachados/recibidos y discrepancias.
 *
 * notes y cancellation_reason son texto libre escrito por usuarios: se
 * devuelven tal cual como DATOS (el frontend los escapa al mostrarlos).
 *
 * @mixin Transfer
 */
class TransferResource extends JsonResource
{
    /** Relaciones que el controlador carga para el detalle. */
    public const RELATIONS = [
        'originWarehouse',
        'destinationWarehouse',
        'items.product',
        'items.lots.lot',
        'items.lots.discrepancy',
        'requester',
        'approver',
        'dispatcher',
        'receiver',
        'canceller',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'origin_warehouse' => $this->whenLoaded('originWarehouse', fn () => self::warehouse($this->originWarehouse)),
            'destination_warehouse' => $this->whenLoaded('destinationWarehouse', fn () => self::warehouse($this->destinationWarehouse)),
            'notes' => $this->notes,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (TransferItem $item) => [
                'id' => $item->id,
                'product' => [
                    'id' => $item->product->id,
                    'code' => $item->product->code,
                    'name' => $item->product->name,
                ],
                'quantity_requested' => $item->quantity_requested,
                'lots' => $item->lots->sortBy('id')->map(fn (TransferItemLot $lot) => [
                    'transfer_item_lot_id' => $lot->id,
                    'lot_id' => $lot->lot_id,
                    'lot_number' => $lot->lot->lot_number,
                    'expires_at' => $lot->lot->expires_at->toDateString(),
                    'quantity_dispatched' => $lot->quantity_dispatched,
                    'quantity_received' => $lot->quantity_received,
                    'discrepancy' => $lot->discrepancy === null ? null : [
                        'id' => $lot->discrepancy->id,
                        'status' => $lot->discrepancy->status->value,
                        'quantity_missing' => $lot->discrepancy->quantity_missing,
                        'resolution_action' => $lot->discrepancy->resolution_action?->value,
                    ],
                ])->values(),
            ])->values()),
            'requested_by' => new UserSummaryResource($this->whenLoaded('requester')),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_by' => $this->approved_by ? new UserSummaryResource($this->whenLoaded('approver')) : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'dispatched_by' => $this->dispatched_by ? new UserSummaryResource($this->whenLoaded('dispatcher')) : null,
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'received_by' => $this->received_by ? new UserSummaryResource($this->whenLoaded('receiver')) : null,
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_by' => $this->cancelled_by ? new UserSummaryResource($this->whenLoaded('canceller')) : null,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            // Acciones que el usuario actual puede ejecutar ya (Policy + estado):
            // el frontend decide qué botones mostrar. El endpoint vuelve a validar.
            'allowed_actions' => $this->allowedActions($request),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedActions(Request $request): array
    {
        $user = $request->user();

        return $user instanceof User ? app(TransferActions::class)->allowedFor($user, $this->resource) : [];
    }

    /**
     * @return array{id: int, code: string, name: string}
     */
    public static function warehouse(Warehouse $warehouse): array
    {
        return ['id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name];
    }
}
