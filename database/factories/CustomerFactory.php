<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'farm_name' => fake()->company().' Farm',
            'phone' => fake()->phoneNumber(),
            'location' => fake()->city(),
            'crop' => fake()->randomElement(['Maize', 'Tomatoes', 'Coffee', 'Beans']),
            'hectares' => fake()->randomFloat(2, 1, 30),
            'notes' => null,
        ];
    }
}
