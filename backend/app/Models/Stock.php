<?php

namespace App\Models;

use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existencia de un lote en una bodega (RN-01). quantity >= 0 por CHECK (RN-03).
 * product_id está denormalizado y la FK compuesta (lot_id, product_id) lo
 * mantiene consistente con el lote.
 *
 * Nunca modificar quantity directamente: usar el servicio de inventario, que
 * bloquea la fila y registra el movimiento de kardex (RN-06).
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $lot_id
 * @property int $product_id
 * @property int $quantity
 */
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['warehouse_id', 'lot_id', 'product_id', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
