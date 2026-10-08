<?php

namespace Database\Factories;

use App\Enums\PrescriptionStatus;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $today = now(config('fartmar.business_timezone'))->toImmutable()->startOfDay();

        return [
            'number' => 'RX-'.fake()->unique()->numerify('########'),
            'patient_id' => Patient::factory(),
            'prescriber_id' => User::factory()->medico(),
            'issued_at' => $today->subDay(),
            'valid_until' => $today->addDays(30)->toDateString(),
            'status' => PrescriptionStatus::Activa,
        ];
    }
}
