<?php

namespace App\Backup;

use App\Models\Backup;

/**
 * Applies the configured retention policy.
 *
 * Backups that are still pending or running are never touched, and only the
 * most recent completed backups inside each frequency window are kept, so a
 * cleanup can never delete the snapshot an operator is about to restore from.
 */
class RetentionService
{
    public function __construct(protected BackupService $backups)
    {
        //
    }

    /**
     * Delete backups that fall outside the retention window.
     *
     * @return array{deleted: array<int, string>, retained: array<int, string>}
     */
    public function prune(bool $pretend = false): array
    {
        $deleted = [];
        $retained = [];

        foreach ($this->windows() as $frequency => $keep) {
            if ($keep < 1) {
                continue;
            }

            $backups = Backup::completed()
                ->frequency($frequency)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            foreach ($backups as $index => $backup) {
                if ($backup->isInFlight()) {
                    continue;
                }

                if ($index < $keep) {
                    $retained[] = $backup->filename;

                    continue;
                }

                $deleted[] = $backup->filename;

                if (! $pretend) {
                    $this->backups->forget($backup);
                }
            }
        }

        return ['deleted' => $deleted, 'retained' => $retained];
    }

    /**
     * The configured keep counts, keyed by frequency.
     *
     * @return array<string, int>
     */
    protected function windows(): array
    {
        return array_intersect_key(
            (array) config('backup.retention', []),
            array_flip(Backup::FREQUENCIES),
        );
    }
}
