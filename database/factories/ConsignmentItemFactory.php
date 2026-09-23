<?php

namespace Database\Factories;

use App\Models\Consignment;
use App\Models\ConsignmentItem;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsignmentItem>
 */
class ConsignmentItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consignment_id' => Consignment::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'unit_id' => Unit::where('abbreviation', 'pc')->value('id'),
            'unit_cost' => 10,
            'line_total' => 10,
        ];
    }
}
