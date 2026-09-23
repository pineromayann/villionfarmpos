<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'expected_date' => fake()->optional()->dateTimeBetween('+1 day', '+1 month'),
            'total' => fake()->randomFloat(2, 100, 5000),
            'note' => null,
            'received_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'received',
            'received_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
