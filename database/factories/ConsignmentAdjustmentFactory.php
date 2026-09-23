<?php

namespace Database\Factories;

use App\Models\ConsignmentAdjustment;
use App\Models\ConsignmentPartner;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsignmentAdjustment>
 */
class ConsignmentAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'partner_id' => ConsignmentPartner::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'reason' => 'return',
            'value' => 0,
            'adjusted_at' => now(),
            'note' => null,
            'created_by' => null,
        ];
    }
}
