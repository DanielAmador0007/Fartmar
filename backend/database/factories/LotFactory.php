<?php

namespace Database\Factories;

use App\Models\Lot;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'lot_number' => 'L-'.fake()->unique()->bothify('??####'),
            'expires_at' => self::businessToday()->addDays(365)->toDateString(),
        ];
    }

    /** Vence dentro de $days días (negativo = ya vencido). */
    public function expiresInDays(int $days): static
    {
        return $this->state(fn () => ['expires_at' => self::businessToday()->addDays($days)->toDateString()]);
    }

    public function expired(): static
    {
        return $this->expiresInDays(-1);
    }

    private static function businessToday(): CarbonImmutable
    {
        return now(config('fartmar.business_timezone'))->toImmutable()->startOfDay();
    }
}
