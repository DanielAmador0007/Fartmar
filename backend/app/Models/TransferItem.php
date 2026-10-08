<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto y cantidad solicitada en un traslado. Los lotes se asignan al
 * despachar (lots()).
 *
 * @property int $id
 * @property int $transfer_id
 * @property int $product_id
 * @property int $quantity_requested
 */
class TransferItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['transfer_id', 'product_id', 'quantity_requested'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
        ];
    }

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<TransferItemLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(TransferItemLot::class);
    }
}
