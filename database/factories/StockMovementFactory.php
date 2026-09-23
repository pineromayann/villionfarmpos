<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'type' => fake()->randomElement(StockMovement::TYPES),
            'quantity' => fake()->randomFloat(2, 1, 100),
            'unit_cost' => fake()->optional()->randomFloat(2, 5, 100),
            'reason' => fake()->optional()->words(3, true),
            'supplier_id' => null,
            'ref_type' => null,
            'ref_id' => null,
            'user_id' => null,
        ];
    }

    public function incoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'in',
        ]);
    }

    public function outgoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'out',
        ]);
    }
}
