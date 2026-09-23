<?php

namespace Database\Factories;

use App\Models\ConsignmentPartner;
use App\Models\ConsignmentSettlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsignmentSettlement>
 */
class ConsignmentSettlementFactory extends Factory
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
            'amount' => 100,
            'settled_at' => now(),
            'note' => null,
            'created_by' => null,
        ];
    }
}
