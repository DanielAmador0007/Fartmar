<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'MED-'.fake()->unique()->numerify('#####'),
            'name' => 'Medicamento '.fake()->unique()->numerify('#####'),
            'presentation' => 'Tableta 500 mg',
            'unit' => 'tableta',
            'is_controlled' => false,
        ];
    }

    /** Medicamento de control especial (RN-05). */
    public function controlled(): static
    {
        return $this->state(fn () => ['is_controlled' => true]);
    }
}
