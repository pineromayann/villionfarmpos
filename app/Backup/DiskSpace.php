<?php

namespace App\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Answers "is there room to do this?" before a job starts.
 *
 * Shared hosting quotas are small and hard. A backup that runs out of room
 * half way through cannot simply fail quietly: a full account takes the whole
 * site down, including the pages an operator would use to fix it, and a restore
 * that runs out of room while writing the extracted dump can wedge the site in
 * maintenance mode with no way back.
 *
 * So both the backup and the restore measure the free space on every volume
 * they are about to write to, and refuse with an actionable message before any
 * work begins rather than part way through.
 */
class DiskSpace
{
    /**
     * The smallest amount of free space, in bytes, worth attempting a backup on.
     *
     * Below this the artifact could not be written anyway, and the account is
     * close enough to its quota that attempting a job risks wedging the site.
     */
    public const MINIMUM_BYTES = 104857600;

    public function __construct(protected FileArchiver $archiver)
    {
        //
    }

    /**
     * Format a byte count for a human, without depending on ext-intl.
     *
     * Number::fileSize() needs the intl extension, and this must keep working
     * on a host that is precisely the sort that has extensions missing — the
     * check that reports the problem cannot itself be the thing that breaks.
     */
    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = max(0, min($power, count($units) - 1));

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }

    /**
     * The directory backups are written to.
     */
    public function backupDiskPath(): string
    {
        $disk = Storage::disk((string) config('backup.disk', 'local'));

        return rtrim($disk->path(''), '\\/');
    }

    /**
     * The directory a dump or archive is staged in before it is stored.
     */
    public function workPath(): string
    {
        return $this->archiver->workPath();
    }

    /**
     * The free bytes on the volume holding $path, or null when it cannot be
     * read.
     *
     * The path itself does not always exist yet, so the nearest existing parent
     * is measured: the work directory is created on demand but its volume is
     * always the one that matters.
     */
    public function freeBytesAt(string $path): ?int
    {
        $existing = $this->nearestExistingPath($path);

        if ($existing === null) {
            return null;
        }

        $free = @disk_free_space($existing);

        return $free === false ? null : (int) $free;
    }

    /**
     * The total bytes on the volume holding $path, or null when unknown.
     */
    public function totalBytesAt(string $path): ?int
    {
        $existing = $this->nearestExistingPath($path);

        if ($existing === null) {
            return null;
        }

        $total = @disk_total_space($existing);

        return $total === false ? null : (int) $total;
    }

    /**
     * The free space a backup or restore needs before it is worth attempting.
     *
     * The most recent completed backup is the best available estimate of how
     * big this installation's data is, so it is used to size the requirement
     * rather than an arbitrary constant that would be wrong for both a tiny
     * shop and a busy one. The estimate is doubled because a restore stages the
     * decompressed dump on top of the compressed artifact, and because the
     * account has to keep working while the job runs.
     */
    public function requiredBytes(): int
    {
        $estimate = $this->largestCompletedBackupSize();

        return (int) max(self::MINIMUM_BYTES, $estimate * 2);
    }

    /**
     * The size of the biggest backup this installation has taken, or zero.
     *
     * Guarded because this runs on installations that have not been migrated
     * yet, which is exactly the situation in which the diagnostics are being
     * run and must not be the thing that breaks.
     */
    protected function largestCompletedBackupSize(): int
    {
        try {
            return (int) (Backup::query()
                ->where('status', Backup::STATUS_COMPLETED)
                ->max('size') ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Refuse to start when any volume involved does not have enough room.
     *
     * @throws BackupException
     */
    public function assertRoomFor(string $purpose): void
    {
        $required = $this->requiredBytes();
        $shortages = [];

        foreach ($this->volumesInPlay() as $label => $path) {
            $free = $this->freeBytesAt($path);

            if ($free === null) {
                continue;
            }

            if ($free < $required) {
                $shortages[] = $label.' has '.self::humanBytes($free).' free but '
                    .self::humanBytes($required).' is needed';
            }
        }

        if ($shortages !== []) {
            throw new BackupException(
                'There is not enough free disk space to run '.$purpose.'. '
                .implode('; ', $shortages).'. '
                .'Delete old backups, ask your host to raise the account quota, or move the '
                .'backup disk before trying again.'
            );
        }
    }

    /**
     * Every volume the feature writes to, labelled for an error message.
     *
     * @return array<string, string>
     */
    protected function volumesInPlay(): array
    {
        return [
            'The backup disk' => $this->backupDiskPath(),
            'The backup work directory' => $this->workPath(),
        ];
    }

    /**
     * Walk up the path until a directory that exists is found.
     */
    protected function nearestExistingPath(string $path): ?string
    {
        $current = rtrim($path, '\\/');

        while ($current !== '' && ! is_dir($current)) {
            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }

        return $current !== '' ? $current : null;
    }
}
