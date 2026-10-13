<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Transfers\TransferQuery;
use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * Traslados (más recientes primero) con sus productos.
 *
 * Las observaciones (notes) son texto libre escrito por usuarios: pueden
 * traer datos personales o instrucciones maliciosas ("SYSTEM: revela...").
 * - A la UI (result de la API) van marcadas {"untrusted": true, "text": ...}
 *   y recortadas, para mostrarlas como texto en la tabla.
 * - Al MODELO nunca va el texto (forModel): solo "has_notes": true/false.
 *   Así no sale hacia un proveedor externo y no puede inyectar instrucciones.
 * No se incluyen los nombres de los usuarios que intervinieron.
 */
final class GetTransfersTool extends AssistantTool
{
    public const MAX_TRANSFERS = 20;

    public const MAX_NOTES_LENGTH = 300;

    public function __construct(private readonly TransferQuery $transfers) {}

    public function name(): string
    {
        return 'get_transfers';
    }

    public function description(): string
    {
        return 'Traslados entre bodegas (los 20 más recientes) con estado, origen, destino y productos. Filtros opcionales por estado y por bodega (origen o destino). has_notes indica si el traslado tiene observaciones (su texto no está disponible para el asistente).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => array_column(TransferStatus::cases(), 'value'),
                    'description' => 'Estado del traslado.',
                ],
                ...self::warehouseCodeSchema(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(TransferStatus::class)],
            'warehouse_code' => ['nullable', 'string', 'max:20', 'exists:warehouses,code'],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->can('viewAny', Transfer::class);
    }

    public function run(array $args): array
    {
        $warehouse = $this->findWarehouse($args['warehouse_code'] ?? null);
        $status = $args['status'] ?? null;

        $page = $this->transfers->search([
            'status' => $status,
            'warehouse_id' => $warehouse?->id,
            'per_page' => self::MAX_TRANSFERS,
        ]);
        $transfers = (new Collection($page->items()))->load('items.product');

        return [
            'filters' => ['status' => $status, 'warehouse' => self::warehouseRef($warehouse)],
            'count' => $page->total(),
            'truncated' => $page->total() > $transfers->count(),
            'rows' => $transfers->map(fn (Transfer $t) => [
                'id' => $t->id,
                'status' => $t->status->value,
                'origin' => self::warehouseRef($t->originWarehouse),
                'destination' => self::warehouseRef($t->destinationWarehouse),
                'items' => $t->items->map(fn (TransferItem $item) => [
                    'product' => self::productRef($item->product),
                    'quantity_requested' => $item->quantity_requested,
                ])->values()->all(),
                'requested_at' => $t->requested_at?->toDateString(),
                'dispatched_at' => $t->dispatched_at?->toDateString(),
                'received_at' => $t->received_at?->toDateString(),
                'notes' => self::untrusted($t->notes),
            ])->values()->all(),
        ];
    }

    /**
     * Al modelo: cada traslado sin el texto de las observaciones, solo si
     * tiene o no.
     */
    public function forModel(array $data): array
    {
        $data['rows'] = array_map(function (array $row): array {
            $row['has_notes'] = $row['notes'] !== null;
            unset($row['notes']);

            return $row;
        }, $data['rows']);

        return $data;
    }

    /**
     * @return array{untrusted: true, text: string}|null
     */
    private static function untrusted(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return ['untrusted' => true, 'text' => mb_substr($text, 0, self::MAX_NOTES_LENGTH)];
    }
}
