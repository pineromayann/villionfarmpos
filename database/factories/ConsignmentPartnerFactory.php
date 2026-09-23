<?php

namespace Database\Factories;

use App\Models\ConsignmentPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsignmentPartner>
 */
class ConsignmentPartnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'contact_person' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'location' => fake()->city(),
            'note' => null,
        ];
    }
}
