<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $presentation
 * @property string $unit
 * @property bool $is_controlled
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'presentation', 'unit', 'is_controlled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_controlled' => 'boolean',
        ];
    }

    /** @return HasMany<Lot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    /** @return HasMany<Stock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /** @return HasMany<StockMinimum, $this> */
    public function stockMinimums(): HasMany
    {
        return $this->hasMany(StockMinimum::class);
    }
}
