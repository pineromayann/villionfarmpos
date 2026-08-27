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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'active_ingredient' => fake()->words(2, true),
            'batch_number' => strtoupper(fake()->bothify('??-####')),
            'expiry_date' => fake()->dateTimeBetween('+1 month', '+2 years'),
            'price' => fake()->randomFloat(2, 5, 50),
            'stock' => fake()->randomFloat(2, 0, 100),
            'unit' => fake()->randomElement(['L', 'kg']),
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => fake()->randomFloat(2, 0, Product::LOW_STOCK_THRESHOLD),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiry_date' => fake()->dateTimeBetween('now', '+'.Product::EXPIRING_SOON_MONTHS.' months'),
        ]);
    }
}
