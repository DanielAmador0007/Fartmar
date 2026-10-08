<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación FEFO: cuánto salió de cada lote para una línea dispensada.
 *
 * @property int $id
 * @property int $dispensation_item_id
 * @property int $lot_id
 * @property int $product_id
 * @property int $quantity
 */
class DispensationItemLot extends Model
{
    /** @var list<string> */
    protected $fillable = ['dispensation_item_id', 'lot_id', 'product_id', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<DispensationItem, $this> */
    public function dispensationItem(): BelongsTo
    {
        return $this->belongsTo(DispensationItem::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
