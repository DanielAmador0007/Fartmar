<?php

namespace App\Models;

use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['code', 'name'];

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

    /** @return HasMany<KardexMovement, $this> */
    public function kardexMovements(): HasMany
    {
        return $this->hasMany(KardexMovement::class);
    }
}
