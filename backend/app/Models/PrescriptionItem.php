<?php

namespace App\Models;

use Database\Factories\PrescriptionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Línea de prescripción. CHECK quantity_dispensed <= quantity_prescribed (RN-04).
 *
 * @property int $id
 * @property int $prescription_id
 * @property int $product_id
 * @property int $quantity_prescribed
 * @property int $quantity_dispensed Acumulado de dispensaciones parciales.
 * @property string|null $instructions
 */
class PrescriptionItem extends Model
{
    /** @use HasFactory<PrescriptionItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['prescription_id', 'product_id', 'quantity_prescribed', 'quantity_dispensed', 'instructions'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_prescribed' => 'integer',
            'quantity_dispensed' => 'integer',
        ];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<DispensationItem, $this> */
    public function dispensationItems(): HasMany
    {
        return $this->hasMany(DispensationItem::class);
    }

    public function remainingQuantity(): int
    {
        return $this->quantity_prescribed - $this->quantity_dispensed;
    }
}
