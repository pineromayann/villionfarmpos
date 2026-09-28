<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->administrator(),
            'action' => ActivityLog::ACTION_BACKUP_COMPLETED,
            'subject_type' => null,
            'subject_id' => null,
            'result' => ActivityLog::RESULT_SUCCESS,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Pest',
            'context' => [],
        ];
    }

    /**
     * Indicate that the recorded action failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => ActivityLog::RESULT_FAILURE,
        ]);
    }
}
