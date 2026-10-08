<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'BOD-'.fake()->unique()->numerify('####'),
            'name' => 'Bodega '.fake()->unique()->numerify('####'),
        ];
    }
}
