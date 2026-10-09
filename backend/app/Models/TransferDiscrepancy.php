<?php

namespace App\Models;

use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cantidad despachada y no recibida de un lote (RN-07), pendiente de resolver.
 *
 * @property int $id
 * @property int $transfer_item_lot_id
 * @property int $quantity_missing
 * @property DiscrepancyStatus $status
 * @property DiscrepancyResolution|null $resolution_action
 * @property string|null $resolution Texto libre del regente: NO confiable.
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 */
class TransferDiscrepancy extends Model
{
    /** @var list<string> */
    protected $fillable = ['transfer_item_lot_id', 'quantity_missing', 'status', 'resolution_action', 'resolution', 'resolved_by', 'resolved_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_missing' => 'integer',
            'status' => DiscrepancyStatus::class,
            'resolution_action' => DiscrepancyResolution::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TransferItemLot, $this> */
    public function transferItemLot(): BelongsTo
    {
        return $this->belongsTo(TransferItemLot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
