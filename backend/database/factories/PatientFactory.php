<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pacientes SINTÉTICOS: el apellido incluye "Ficticio" para que nunca se
 * confundan con personas reales.
 *
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_type' => 'CC',
            'document_number' => fake()->unique()->numerify('99########'),
            'first_name' => fake()->firstName(),
            'last_name' => 'Ficticio '.fake()->numerify('###'),
            'birth_date' => fake()->dateTimeBetween('-90 years', '-1 year')->format('Y-m-d'),
            'phone' => fake()->numerify('300#######'),
        ];
    }
}
