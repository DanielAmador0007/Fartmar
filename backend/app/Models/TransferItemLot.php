<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Lote despachado en un traslado y cuánto se recibió de él.
 * CHECK 0 <= quantity_received <= quantity_dispatched; NULL = aún en tránsito.
 *
 * @property int $id
 * @property int $transfer_item_id
 * @property int $lot_id
 * @property int $product_id
 * @property int $quantity_dispatched
 * @property int|null $quantity_received
 */
class TransferItemLot extends Model
{
    /** @var list<string> */
    protected $fillable = ['transfer_item_id', 'lot_id', 'product_id', 'quantity_dispatched', 'quantity_received'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_dispatched' => 'integer',
            'quantity_received' => 'integer',
        ];
    }

    /** @return BelongsTo<TransferItem, $this> */
    public function transferItem(): BelongsTo
    {
        return $this->belongsTo(TransferItem::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return HasOne<TransferDiscrepancy, $this> */
    public function discrepancy(): HasOne
    {
        return $this->hasOne(TransferDiscrepancy::class);
    }
}
