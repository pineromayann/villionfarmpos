<?php

namespace App\Jobs;

use App\Backup\BackupException;
use App\Backup\BackupService;
use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A dump either completes or is reported as failed. Retrying a half
     * written dump in the background would only hide the real error.
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(public Backup $backup)
    {
        $this->onQueue((string) config('backup.queue.name', 'backups'));
        $this->timeout = (int) config('backup.timeout', 3600);

        if ($connection = config('backup.queue.connection')) {
            $this->onConnection($connection);
        }
    }

    /**
     * Execute the job.
     *
     * @throws BackupException
     */
    public function handle(BackupService $service): void
    {
        $service->run($this->backup);
    }

    /**
     * Record the failure so a broken job never leaves a backup stuck in the
     * running state with no explanation.
     */
    public function failed(?Throwable $exception): void
    {
        app(BackupService::class)->fail($this->backup, $exception ?? new BackupException('The backup job failed without reporting an error.'));
    }
}
