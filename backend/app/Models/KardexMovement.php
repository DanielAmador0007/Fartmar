<?php

namespace App\Models;

use App\Enums\KardexType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Movimiento de kardex (RN-06). Solo inserción: un trigger de PostgreSQL
 * rechaza cualquier UPDATE o DELETE. Las correcciones son movimientos AJUSTE.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $product_id
 * @property int $lot_id
 * @property KardexType $type
 * @property int $quantity Siempre > 0.
 * @property int $direction +1 entrada, -1 salida.
 * @property int $balance_after Saldo del lote en la bodega tras el movimiento.
 * @property string|null $reason
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int $user_id
 * @property string|null $correlation_id
 * @property CarbonImmutable $created_at
 */
class KardexMovement extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'lot_id',
        'type',
        'quantity',
        'direction',
        'balance_after',
        'reason',
        'reference_type',
        'reference_id',
        'user_id',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => KardexType::class,
            'quantity' => 'integer',
            'direction' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
