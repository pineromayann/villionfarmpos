<?php

namespace App\Console\Commands;

use App\Backup\BackupService;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class CreateBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:create
                            {--database : Capture the database only}
                            {--full : Capture the database plus the business critical files}
                            {--frequency=daily : Retention group: daily, weekly or monthly}
                            {--user= : The user id to attribute the backup to}
                            {--sync : Run the backup inline instead of dispatching a job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a restorable snapshot of the database and the business critical files';

    /**
     * Execute the console command.
     */
    public function handle(BackupService $service): int
    {
        $frequency = $this->option('frequency');
        $userId = $this->option('user');

        if (! $this->frequencyIsValid($frequency)) {
            return self::FAILURE;
        }

        $user = $this->resolveUser($userId);

        if ($user === false) {
            return self::FAILURE;
        }

        $type = $this->option('full') ? Backup::TYPE_FULL : Backup::TYPE_DATABASE;
        $backup = $service->request($type, $user, $this->normaliseFrequency($frequency));

        $this->components->twoColumnDetail('Backup', $backup->filename);
        $this->components->twoColumnDetail('Type', $backup->typeLabel());

        if ($this->option('sync') || config('queue.default') === 'sync') {
            $this->components->info('Running the backup inline.');

            try {
                $service->run($backup);
            } catch (Throwable $e) {
                $this->newLine();
                $this->components->error($e->getMessage());
                $this->line('  The backup has been marked as failed. Fix the problem above and run the command again.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->components->twoColumnDetail('Size', $backup->refresh()->humanSize());
            $this->components->twoColumnDetail('Status', $backup->status);
            $this->components->info('Backup completed.');

            return self::SUCCESS;
        }

        RunBackup::dispatch($backup);

        $this->components->info('Backup queued. Run "php artisan queue:work --queue=backups" to process it.');

        return self::SUCCESS;
    }

    /**
     * Determine whether the requested retention frequency is one we keep.
     */
    protected function frequencyIsValid(?string $frequency): bool
    {
        if ($frequency === null || $frequency === '' || $frequency === 'none') {
            return true;
        }

        if (in_array($frequency, Backup::FREQUENCIES, true)) {
            return true;
        }

        $this->components->error('Unknown frequency. Use one of: '.implode(', ', Backup::FREQUENCIES).' or "none".');

        return false;
    }

    /**
     * Normalise the frequency option to the value stored on the backup row.
     */
    protected function normaliseFrequency(?string $frequency): ?string
    {
        return in_array($frequency, Backup::FREQUENCIES, true) ? $frequency : null;
    }

    /**
     * Resolve the user the backup is attributed to.
     *
     * @return User|false|null Null when no user was requested, false when the
     *                         requested id does not exist.
     */
    protected function resolveUser(?string $id): User|false|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = User::find($id);

        if ($user === null) {
            $this->components->error("No user exists with the id [{$id}].");
        }

        return $user ?? false;
    }
}
