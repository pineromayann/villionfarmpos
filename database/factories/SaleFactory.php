<?php

namespace Database\Factories;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 200);

        return [
            'customer_id' => null,
            'subtotal' => $subtotal,
            'discount' => 0,
            'total' => $subtotal,
            'payment_method' => fake()->randomElement(['cash', 'card', 'mobile_money']),
        ];
    }

    /**
     * Give the sale an order receipt number, the way the point of sale does.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Sale $sale) {
            $sale->update(['receipt_number' => $sale->deriveReceiptNumber()]);
        });
    }
}
