<?php

namespace Database\Factories;

use App\Models\ConsignmentPartner;
use App\Models\ConsignmentSale;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsignmentSale>
 */
class ConsignmentSaleFactory extends Factory
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
            'sale_id' => null,
            'customer_id' => null,
            'product_id' => Product::factory(),
            'quantity' => 1,
            'unit_id' => Unit::where('abbreviation', 'pc')->value('id'),
            'unit_price' => 12,
            'line_total' => 12,
            'unit_cost' => 10,
            'payable_amount' => 10,
            'sold_at' => now(),
            'created_by' => null,
        ];
    }
}
