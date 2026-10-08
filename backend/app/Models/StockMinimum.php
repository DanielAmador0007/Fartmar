<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock mínimo de un producto en una bodega (RN-11).
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $product_id
 * @property int $min_quantity
 */
class StockMinimum extends Model
{
    /** @var list<string> */
    protected $fillable = ['warehouse_id', 'product_id', 'min_quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
