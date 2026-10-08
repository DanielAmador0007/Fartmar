<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cantidad dispensada de una línea de prescripción. Los lotes de los que
 * salió están en lots() (FEFO, multi-lote).
 *
 * @property int $id
 * @property int $dispensation_id
 * @property int $prescription_item_id
 * @property int $product_id
 * @property int $quantity
 */
class DispensationItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['dispensation_id', 'prescription_item_id', 'product_id', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Dispensation, $this> */
    public function dispensation(): BelongsTo
    {
        return $this->belongsTo(Dispensation::class);
    }

    /** @return BelongsTo<PrescriptionItem, $this> */
    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<DispensationItemLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(DispensationItemLot::class);
    }
}
