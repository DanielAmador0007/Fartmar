<?php

namespace App\Http\Resources;

use App\Domain\Transfers\TransferActions;
use App\Domain\Transfers\TransferQuery;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\TransferItemLot;
use App\Models\User;
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
    /** Relaciones del detalle: las del listado + líneas, lotes y discrepancias. */
    public const RELATIONS = [
        ...TransferQuery::LIST_RELATIONS,
        'items.product',
        'items.lots.lot',
        'items.lots.discrepancy',
    ];

    /** Actor (columna *_by) => relación con el usuario. */
    private const ACTORS = [
        'requested_by' => 'requester',
        'approved_by' => 'approver',
        'dispatched_by' => 'dispatcher',
        'received_by' => 'receiver',
        'cancelled_by' => 'canceller',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'origin_warehouse' => new WarehouseSummaryResource($this->whenLoaded('originWarehouse')),
            'destination_warehouse' => new WarehouseSummaryResource($this->whenLoaded('destinationWarehouse')),
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
            'requested_by' => $this->actor('requested_by'),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_by' => $this->actor('approved_by'),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'dispatched_by' => $this->actor('dispatched_by'),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'received_by' => $this->actor('received_by'),
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_by' => $this->actor('cancelled_by'),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            // Acciones que el usuario actual puede ejecutar ya (Policy + estado):
            // el frontend decide qué botones mostrar. El endpoint vuelve a validar.
            'allowed_actions' => $this->allowedActions($request),
        ];
    }

    /**
     * Un actor siempre con la misma forma: {id, name, role} o null si ese paso
     * aún no ocurrió. Si la relación no se cargó, la clave se omite (nunca
     * dispara una consulta por fila); listado y detalle cargan todas.
     *
     * @param  key-of<self::ACTORS>  $column
     */
    private function actor(string $column): mixed
    {
        $relation = self::ACTORS[$column];

        return $this->whenLoaded($relation, function () use ($relation): ?UserSummaryResource {
            $user = $this->resource->getRelation($relation);

            return $user instanceof User ? new UserSummaryResource($user) : null;
        });
    }

    /**
     * @return list<string>
     */
    private function allowedActions(Request $request): array
    {
        $user = $request->user();

        return $user instanceof User ? app(TransferActions::class)->allowedFor($user, $this->resource) : [];
    }
}
