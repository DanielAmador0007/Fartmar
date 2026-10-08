<?php

namespace Database\Factories;

use App\Models\Lot;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Crea la fila de existencia SIN movimiento de kardex: úsese solo en pruebas
 * de esquema. Las pruebas de negocio deben pasar por el servicio de inventario.
 *
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'lot_id' => Lot::factory(),
            // Siempre el producto real del lote (la FK compuesta lo exige).
            'product_id' => fn (array $attributes) => Lot::query()->findOrFail($attributes['lot_id'])->product_id,
            'quantity' => 10,
        ];
    }
}
