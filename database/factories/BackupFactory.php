<?php

namespace Database\Factories;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = sprintf(
            '%s-%s',
            config('backup.filename_prefix', 'villonfarm').'-database-'.now()->format('Y-m-d-His'),
            fake()->unique()->numerify('###'),
        ).'.sql.gz';

        return [
            'filename' => $filename,
            'disk' => config('backup.disk', 'local'),
            'path' => trim((string) config('backup.directory', 'backups'), '/').'/'.$filename,
            'type' => Backup::TYPE_DATABASE,
            'size' => fake()->numberBetween(1024, 5_000_000),
            'checksum' => hash('sha256', $filename),
            'status' => Backup::STATUS_COMPLETED,
            'frequency' => 'daily',
            'created_by' => User::factory()->administrator(),
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ];
    }

    /**
     * Indicate that the backup is a full database plus files backup.
     */
    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Backup::TYPE_FULL,
            'filename' => preg_replace('/-database-/', '-full-', $attributes['filename']),
            'path' => preg_replace('/-database-/', '-full-', $attributes['path']),
        ]);
    }

    /**
     * Indicate that the backup has not been started yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Backup::STATUS_PENDING,
            'size' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the backup is currently running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Backup::STATUS_RUNNING,
            'size' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the backup failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Backup::STATUS_FAILED,
            'size' => null,
            'checksum' => null,
            'completed_at' => now(),
            'error_message' => 'The mysqldump process exited with status 2.',
        ]);
    }
}
