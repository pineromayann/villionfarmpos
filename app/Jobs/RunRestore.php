<?php

namespace App\Jobs;

use App\Backup\BackupException;
use App\Backup\RestoreService;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunRestore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Restoring is never retried automatically. A failed import may have left
     * the database partially replaced, and a second attempt would compound the
     * damage rather than recover from it.
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(public Backup $backup, public ?User $user = null)
    {
        $this->onQueue((string) config('backup.queue.restore_name', 'restores'));
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
    public function handle(RestoreService $service): void
    {
        $service->restore($this->backup, $this->user);
    }

    /**
     * Leave a breadcrumb even if the process was killed outright.
     */
    public function failed(?Throwable $exception): void
    {
        Log::critical('The restore job failed. Check the safety backup taken before it started.', [
            'backup_id' => $this->backup->id,
            'filename' => $this->backup->filename,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
