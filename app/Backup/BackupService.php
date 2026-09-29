<?php

namespace App\Backup;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates backup creation.
 *
 * The service is deliberately read-only with respect to business data: it
 * dumps the database and copies files, and never writes to a business table.
 * No stock quantity, sale, purchase, receipt, movement, settlement or payment
 * record can be touched as a side effect of taking a backup.
 */
class BackupService
{
    public const SNAPSHOT_ENTRY = 'database.sql.gz';

    public function __construct(
        protected FileArchiver $archiver,
        protected BinaryLocator $locator,
        protected PrivateDisk $privateDisk,
        protected DiskSpace $space,
    ) {
        //
    }

    /**
     * Record a new backup and dispatch it to the backup queue.
     */
    public function request(string $type, ?User $user = null, ?string $frequency = null): Backup
    {
        $type = $type === Backup::TYPE_FULL ? Backup::TYPE_FULL : Backup::TYPE_DATABASE;
        $filename = $this->filenameFor($type);

        // Refused before any record or artifact exists, so a disk that the web
        // can serve can never end up holding a backup.
        $disk = config('backup.disk', 'local');

        $this->privateDisk->assertIsPrivate($disk);

        $backup = Backup::create([
            'filename' => $filename,
            'disk' => $disk,
            'path' => $this->pathFor($filename),
            'type' => $type,
            'status' => Backup::STATUS_PENDING,
            'frequency' => $frequency,
            'database_name' => config('database.connections.'.config('database.default').'.database'),
            'created_by' => $user?->id,
        ]);

        ActivityLogger::record(ActivityLog::ACTION_BACKUP_CREATED, $backup, [
            'user' => $user,
            'context' => ['type' => $type, 'frequency' => $frequency],
        ]);

        return $backup;
    }

    /**
     * Perform the backup described by the given record.
     *
     * On failure the record is marked failed, the error is stored and every
     * partially written artifact is removed.
     *
     * @throws BackupException
     */
    public function run(Backup $backup): Backup
    {
        $lock = Cache::lock('backup:run:'.$backup->id, (int) config('backup.timeout', 3600) + 60);

        if (! $lock->get()) {
            throw new BackupException("Backup [{$backup->filename}] is already running.");
        }

        try {
            return $this->perform($backup);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Carry out the backup once exclusive access to the record is held.
     *
     * @throws BackupException
     */
    protected function perform(Backup $backup): Backup
    {
        $workDirectory = $this->archiver->workPath().DIRECTORY_SEPARATOR.Str::uuid()->toString();

        $backup->forceFill([
            'status' => Backup::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ])->save();

        Log::info('Backup started.', ['backup_id' => $backup->id, 'filename' => $backup->filename]);

        try {
            // Checked before any work begins: a job that runs out of room
            // half way through leaves a truncated artifact and can take a
            // shared hosting quota down with it.
            $this->space->assertRoomFor('a backup');

            $this->archiver->ensureDirectoryExists($workDirectory);

            $snapshotPath = $workDirectory.DIRECTORY_SEPARATOR.self::SNAPSHOT_ENTRY;
            $snapshot = $this->snapshot()->dumpTo($snapshotPath);

            $artifactPath = $this->artifactFor($backup, $workDirectory);
            $included = $backup->type === Backup::TYPE_FULL
                ? $this->archiver->archiveTo($artifactPath, [self::SNAPSHOT_ENTRY => $snapshotPath])
                : $this->promote($snapshotPath, $artifactPath);

            $this->store($backup, $artifactPath);

            $summary = $this->summariseSnapshot($snapshot);

            $backup->forceFill([
                'status' => Backup::STATUS_COMPLETED,
                'size' => Storage::disk($backup->disk)->size($backup->path),
                'checksum' => hash_file('sha256', $artifactPath),
                'included_paths' => $included,
                'snapshot' => $summary,
                'completed_at' => now(),
            ])->save();

            ActivityLogger::record(ActivityLog::ACTION_BACKUP_COMPLETED, $backup, [
                'user' => $backup->creator,
                'context' => $summary + ['size' => $backup->size],
            ]);

            Log::info('Backup completed.', [
                'backup_id' => $backup->id,
                'filename' => $backup->filename,
                'size' => $backup->size,
                'driver' => $summary['driver'],
                'tables' => $summary['tables'],
            ]);

            return $backup->refresh();
        } catch (Throwable $e) {
            $this->fail($backup, $e);

            throw $e instanceof BackupException
                ? $e
                : new BackupException('The backup failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->deleteDirectory($workDirectory);
        }
    }

    /**
     * Mark a backup as failed, recording the reason and clearing any partial
     * artifact from the configured disk.
     */
    public function fail(Backup $backup, Throwable $exception): Backup
    {
        $backup->forceFill([
            'status' => Backup::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => Str::limit($exception->getMessage(), 2000),
        ])->save();

        $this->discardArtifact($backup);

        ActivityLogger::record(ActivityLog::ACTION_BACKUP_FAILED, $backup, [
            'user' => $backup->creator,
            'result' => ActivityLog::RESULT_FAILURE,
            'context' => ['error' => $exception->getMessage()],
        ]);

        Log::error('Backup failed.', [
            'backup_id' => $backup->id,
            'filename' => $backup->filename,
            'exception' => $exception->getMessage(),
        ]);

        return $backup;
    }

    /**
     * Delete a backup's file and its database record.
     */
    public function forget(Backup $backup): void
    {
        $this->discardArtifact($backup);
        $backup->delete();
    }

    /**
     * Remove any stored artifact for the given backup.
     */
    public function discardArtifact(Backup $backup): void
    {
        if (! $backup->hasSafePath()) {
            return;
        }

        $disk = Storage::disk($backup->disk);

        if ($disk->exists($backup->path)) {
            $disk->delete($backup->path);
        }
    }

    /**
     * Generate a safe, unique filename. Filenames are never derived from user
     * input; the timestamp and a collision counter are the only variable parts.
     */
    public function filenameFor(string $type, ?DateTimeInterface $moment = null): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) config('backup.filename_prefix', 'villonfarm')) ?: 'backup';
        $stamp = ($moment ?? now())->format('Y-m-d-His');
        $extension = $type === Backup::TYPE_FULL ? 'zip' : 'sql.gz';
        $base = "{$prefix}-{$type}-{$stamp}";

        $candidate = "{$base}.{$extension}";
        $attempt = 1;

        while (Backup::where('filename', $candidate)->exists()) {
            $candidate = $base.'-'.(++$attempt).'.'.$extension;
        }

        return $candidate;
    }

    /**
     * The disk-relative path a generated filename is stored at.
     */
    public function pathFor(string $filename): string
    {
        return trim((string) config('backup.directory', 'backups'), '/').'/'.$filename;
    }

    /**
     * The database snapshot strategy for the current connection, preferring
     * mysqldump when its binary is available.
     */
    public function snapshot(): DatabaseSnapshot
    {
        $driver = config('database.connections.'.config('database.default').'.driver');
        $excluded = (array) config('backup.exclude_tables', []);

        return match ($driver) {
            'mysql' => $this->locator->find('mysqldump') !== null
                ? new MySqlDumpSnapshot($this->locator, $excluded, app(PrivateDefaultsFile::class))
                : new NativeMysqlSnapshot($excluded),
            'sqlite' => new SqliteSnapshot,
            default => throw new BackupException("Backups are not supported for the [{$driver}] database driver."),
        };
    }

    /**
     * Reduce what a snapshot reported to the small set of facts worth keeping.
     *
     * The table names themselves are already inside the dump; what an operator
     * actually needs is proof that the backup captured something, so only the
     * count is recorded. A driver that cannot count rows records null rather
     * than a zero that would read as "this backup is empty".
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{driver: string|null, tables: int, rows: int|null}
     */
    protected function summariseSnapshot(array $snapshot): array
    {
        return [
            'driver' => $snapshot['driver'] ?? null,
            'tables' => is_array($snapshot['tables'] ?? null) ? count($snapshot['tables']) : 0,
            'rows' => isset($snapshot['rows']) ? (int) $snapshot['rows'] : null,
        ];
    }

    /**
     * The staging path of the final artifact for a backup.
     */
    protected function artifactFor(Backup $backup, string $workDirectory): string
    {
        return $workDirectory.DIRECTORY_SEPARATOR.$backup->filename;
    }

    /**
     * Move a finished snapshot to its final staged name.
     *
     * @return array<int, string>
     */
    protected function promote(string $from, string $to): array
    {
        if (! @rename($from, $to)) {
            if (! @copy($from, $to)) {
                throw new BackupException('Unable to finalise the database dump.');
            }

            @unlink($from);
        }

        return [];
    }

    /**
     * Write the staged artifact to the configured disk.
     */
    protected function store(Backup $backup, string $artifactPath): void
    {
        $stream = @fopen($artifactPath, 'rb');

        if ($stream === false) {
            throw new BackupException('Unable to read the finished backup artifact.');
        }

        $written = Storage::disk($backup->disk)->put($backup->path, $stream, ['visibility' => 'private']);
        fclose($stream);

        if ($written === false) {
            throw new BackupException("Unable to write the backup to the [{$backup->disk}] disk. Check its configuration and free space.");
        }
    }

    /**
     * Recursively delete a staging directory.
     */
    protected function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
