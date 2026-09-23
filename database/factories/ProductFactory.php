<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
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
            'category' => fake()->randomElement(Product::CATEGORIES),
            'active_ingredient' => fake()->words(2, true),
            'batch_number' => strtoupper(fake()->bothify('??-####')),
            'expiry_date' => fake()->dateTimeBetween('+1 month', '+2 years'),
            'price' => fake()->randomFloat(2, 5, 50),
            'cost_price' => fake()->randomFloat(2, 5, 50),
            'dealers_price_cod' => fake()->randomFloat(2, 5, 50),
            'terms_30_days' => fake()->optional()->randomFloat(2, 5, 50),
            'stock' => fake()->randomFloat(2, 0, 100),
            'base_unit_id' => Unit::where('abbreviation', 'L')->value('id'),
            'note' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Product $product) {
            if ($product->base_unit_id === null) {
                return;
            }

            ProductUnit::updateOrCreate(
                ['product_id' => $product->id, 'unit_id' => $product->base_unit_id],
                ['conversion_to_base' => 1, 'is_base' => true]
            );
        });
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
