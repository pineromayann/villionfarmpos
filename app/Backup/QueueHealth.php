<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detects the failure mode where backups are queued but nothing ever runs them.
 *
 * On a host with no queue worker, a backup job sits in the jobs table forever.
 * The application has no error to show for this: the job was accepted, so the
 * screen says it "will start shortly" and then simply never happens. Looking
 * for a job that has been waiting longer than it should is the cheapest way to
 * turn that silence into something the operator can act on.
 */
class QueueHealth
{
    /**
     * Whether backup work is queued but has been waiting too long to be
     * explained by an ordinary slow run.
     */
    public function isStalled(): bool
    {
        if ($this->runsInline() || ! Schema::hasTable('jobs')) {
            return false;
        }

        $waitedLongerThan = (int) config('backup.queue.stalled_after_minutes', 5);

        return DB::table('jobs')
            ->whereIn('queue', $this->queues())
            ->where('created_at', '<=', now()->subMinutes($waitedLongerThan))
            ->exists();
    }

    /**
     * Whether jobs are executed inside the request instead of by a worker.
     */
    protected function runsInline(): bool
    {
        return config('queue.default') === 'sync'
            || config('backup.queue.connection') === 'sync';
    }

    /**
     * The queues backup and restore jobs are pushed onto.
     *
     * @return array<int, string>
     */
    protected function queues(): array
    {
        return array_values(array_filter([
            (string) config('backup.queue.name', 'backups'),
            (string) config('backup.queue.restore_name', 'restores'),
        ]));
    }
}
