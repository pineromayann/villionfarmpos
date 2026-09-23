<?php

namespace Database\Factories;

use App\Models\Consignment;
use App\Models\ConsignmentPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consignment>
 */
class ConsignmentFactory extends Factory
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
            'received_at' => now(),
            'note' => null,
            'created_by' => null,
        ];
    }
}
