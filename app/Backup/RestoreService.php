<?php

namespace App\Backup;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Restores the database, and optionally the archived files, from a completed
 * backup.
 *
 * The sequence is deliberately paranoid: validate the artifact, take a safety
 * backup of the current database, then replace the data. If the process dies
 * part way through, the safety backup is still on disk and the application is
 * left in maintenance mode, so the worst case is a recoverable outage rather
 * than silent data loss.
 */
class RestoreService
{
    /**
     * The cache lock held for the duration of a restore, if one is held.
     */
    protected ?Lock $restoreLock = null;

    public function __construct(
        protected BackupService $backups,
        protected FileArchiver $archiver,
        protected BinaryLocator $locator,
        protected RestoreAccess $access,
        protected DiskSpace $space,
        protected PrivateDefaultsFile $defaults,
    ) {
        //
    }

    /**
     * Restore the given backup.
     *
     * @return array{backup: Backup, safety: Backup|null, files: array<int, string>}
     *
     * @throws BackupException
     */
    public function restore(Backup $backup, ?User $user = null): array
    {
        if (! $backup->isCompleted()) {
            throw new BackupException("Backup [{$backup->filename}] is not a completed backup and cannot be restored.");
        }

        if (! $this->access->enabled()) {
            throw new BackupException('Restoring is switched off. An administrator can turn it on from the Backups screen.');
        }

        $workDirectory = $this->archiver->workPath().DIRECTORY_SEPARATOR.Str::uuid()->toString();
        $maintenance = config('backup.restore.maintenance_mode') === true;
        $succeeded = false;
        $safety = null;
        $restoredFiles = [];

        $this->acquireRestoreLock();

        try {
            $this->validate($backup);
            $this->archiver->ensureDirectoryExists($workDirectory);

            // Checked before maintenance mode is entered. A restore that
            // cannot possibly fit is reported with the site still running,
            // rather than taking the site down first and failing afterwards.
            $this->space->assertRoomFor('a restore');

            ActivityLogger::record(ActivityLog::ACTION_RESTORE_STARTED, $backup, [
                'user' => $user,
                'context' => ['disk' => $backup->disk, 'path' => $backup->path],
            ]);

            Log::critical('Restore started.', [
                'backup_id' => $backup->id,
                'filename' => $backup->filename,
                'user_id' => $user?->id,
            ]);

            if ($maintenance) {
                $this->enterMaintenanceMode();
            }

            try {
                if (config('backup.restore.safety_backup') === true) {
                    $safety = $this->backups->run($this->backups->request(Backup::TYPE_DATABASE, $user));
                }

                $attributes = $safety?->attributesToArray() ?? null;

                $archive = $backup->type === Backup::TYPE_FULL ? $this->openArchive($backup, $workDirectory) : null;

                try {
                    $snapshotPath = $this->extractSnapshot($backup, $workDirectory, $archive);
                    $staged = $archive !== null
                        ? $this->archiver->stage($archive, $workDirectory.DIRECTORY_SEPARATOR.'staged')
                        : [];
                } finally {
                    $archive?->close();
                }

                $this->import($snapshotPath);
                $this->recoverAuditTrail($backup, $user, $attributes);

                $restoredFiles = $this->archiver->commit($staged);

                Artisan::call('migrate', ['--force' => true]);
                Artisan::call('cache:clear');
                $this->purgeSessions();
                $this->verify($backup);

                $succeeded = true;

                ActivityLogger::record(ActivityLog::ACTION_RESTORE_COMPLETED, $backup, [
                    'user' => $user,
                    'context' => [
                        'safety_backup' => $safety?->filename,
                        'files_restored' => count($restoredFiles),
                    ],
                ]);

                Log::critical('Restore completed.', [
                    'backup_id' => $backup->id,
                    'filename' => $backup->filename,
                    'safety_backup' => $safety?->filename,
                    'files_restored' => count($restoredFiles),
                ]);
            } catch (Throwable $e) {
                $this->recoverAuditTrail($backup, $user, null);

                ActivityLogger::record(ActivityLog::ACTION_RESTORE_FAILED, $backup, [
                    'user' => $user,
                    'result' => ActivityLog::RESULT_FAILURE,
                    'context' => ['error' => $e->getMessage()],
                ]);

                Log::critical('Restore failed. The application has been left in maintenance mode on purpose.', [
                    'backup_id' => $backup->id,
                    'filename' => $backup->filename,
                    'safety_backup' => $safety?->filename,
                    'exception' => $e->getMessage(),
                ]);

                throw $e instanceof BackupException
                    ? $e
                    : new BackupException('The restore failed: '.$e->getMessage(), 0, $e);
            }
        } finally {
            if ($succeeded && $maintenance) {
                $this->leaveMaintenanceMode();
            }

            $this->releaseRestoreLock();

            $this->deleteDirectory($workDirectory);
        }

        return ['backup' => $backup, 'safety' => $safety, 'files' => $restoredFiles];
    }

    /**
     * Take exclusive ownership of the restore for this process.
     *
     * Two operators clicking restore at the same time must never import
     * concurrently, and the lock is always released again even when validation
     * or the import throws.
     *
     * @throws BackupException
     */
    protected function acquireRestoreLock(): void
    {
        $lock = Cache::lock('backup:restore', (int) config('backup.timeout', 3600) + 60);

        if (! $lock->get()) {
            throw new BackupException('Another restore is already running. Wait for it to finish before starting another.');
        }

        $this->restoreLock = $lock;
    }

    /**
     * Give up ownership of the restore.
     */
    protected function releaseRestoreLock(): void
    {
        $this->restoreLock?->release();
        $this->restoreLock = null;
    }

    /**
     * Confirm the artifact is present, non-empty, intact and really is the file
     * type its name claims, before anything is written to the database.
     *
     * @throws BackupException
     */
    public function validate(Backup $backup): void
    {
        $disk = Storage::disk($backup->disk);

        if (! $backup->hasSafePath() || ! $disk->exists($backup->path)) {
            throw new BackupException("The backup file [{$backup->filename}] is no longer present on the [{$backup->disk}] disk.");
        }

        $size = $disk->size($backup->path);

        if ($size === false || $size < 1) {
            throw new BackupException("The backup file [{$backup->filename}] is empty or unreadable.");
        }

        $expected = $backup->type === Backup::TYPE_FULL ? "PK\x03\x04" : "\x1f\x8b";

        if (! str_starts_with($this->readHead($backup, 4), $expected)) {
            throw new BackupException(
                "The backup file [{$backup->filename}] does not look like a valid "
                .($backup->type === Backup::TYPE_FULL ? 'zip archive' : 'gzipped SQL dump')
                .' and will not be restored.'
            );
        }

        $this->verifyChecksum($backup);
    }

    /**
     * Re-hash the stored artifact and compare it with the checksum recorded
     * when the backup was taken, so a truncated or tampered file is rejected
     * rather than imported.
     *
     * @throws BackupException
     */
    protected function verifyChecksum(Backup $backup): void
    {
        if ($backup->checksum === null || $backup->checksum === '') {
            return;
        }

        $stream = Storage::disk($backup->disk)->readStream($backup->path);

        if ($stream === false) {
            throw new BackupException("Unable to read the backup file [{$backup->filename}].");
        }

        $context = hash_init('sha256');

        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);

            if ($chunk === false) {
                break;
            }

            hash_update($context, $chunk);
        }

        fclose($stream);

        $actual = hash_final($context);

        if (! hash_equals($backup->checksum, $actual)) {
            throw new BackupException(
                "The backup file [{$backup->filename}] is corrupt: its checksum does not match the one recorded when it was taken."
            );
        }
    }

    /**
     * Re-assert the audit trail and the safety backup after the import.
     *
     * The import replaces the live database with the contents of the selected
     * backup, which also replaces the activity log and the backups table. The
     * rows written before the import would therefore be gone, so they are
     * written again against the restored database.
     *
     * @param  array<string, mixed>|null  $safety
     */
    protected function recoverAuditTrail(Backup $backup, ?User $user, ?array $safety): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $this->closeInterruptedBackupRows();

        $restored = $safety !== null && Schema::hasTable('backups')
            ? Backup::firstWhere('filename', $safety['filename'])
            : null;

        if ($safety !== null && $restored === null) {
            $restored = Backup::withoutTimestamps(fn () => Backup::create($safety));
        }

        ActivityLogger::record(ActivityLog::ACTION_RESTORE_STARTED, $backup, [
            'user' => $user,
            'context' => ['recovered' => true],
        ]);

        if ($restored !== null) {
            ActivityLogger::record(ActivityLog::ACTION_BACKUP_COMPLETED, $restored, [
                'user' => $user,
                'context' => ['recovered' => true],
            ]);
        }
    }

    /**
     * Mark abandoned backup rows as failed.
     *
     * A backup row is written while the dump is still being produced, so the
     * copy of the backups table inside its own artifact always shows the backup
     * as still running. Restoring that artifact would otherwise leave a row
     * stuck in "running" forever, which misleads operators and is never cleaned
     * up by the retention job.
     */
    protected function closeInterruptedBackupRows(): void
    {
        if (! Schema::hasTable('backups')) {
            return;
        }

        Backup::whereIn('status', [Backup::STATUS_PENDING, Backup::STATUS_RUNNING])
            ->update([
                'status' => Backup::STATUS_FAILED,
                'error_message' => 'Interrupted: this backup was still running when the database was restored.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Prove the restored database is actually usable before the application is
     * returned to service.
     *
     * @throws BackupException
     */
    protected function verify(Backup $backup): void
    {
        $required = ['migrations', 'users'];

        if ($backup->type === Backup::TYPE_FULL && in_array('users', $required, true)) {
            $required[] = 'products';
            $required[] = 'sales';
            $required[] = 'sale_items';
            $required[] = 'stock_movements';
        }

        foreach ($required as $table) {
            if (! Schema::hasTable($table)) {
                throw new BackupException(
                    "The restore did not produce a usable database: the [{$table}] table is missing. "
                    .'The application has been left in maintenance mode.'
                );
            }
        }

        DB::table('migrations')->count();
    }

    /**
     * Copy the compressed dump out of the backup disk and decompress it.
     *
     * @param  ZipArchive|null  $archive  An already opened full backup archive.
     * @return string Absolute path of the plain .sql file.
     */
    protected function extractSnapshot(Backup $backup, string $workDirectory, ?ZipArchive $archive = null): string
    {
        $compressed = $workDirectory.DIRECTORY_SEPARATOR.'database.sql.gz';

        if ($backup->type === Backup::TYPE_DATABASE) {
            $stream = Storage::disk($backup->disk)->readStream($backup->path);

            if ($stream === false) {
                throw new BackupException("Unable to read the backup file [{$backup->filename}].");
            }

            $target = @fopen($compressed, 'wb');
            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);
        } else {
            $zip = $archive ?? $this->openArchive($backup, $workDirectory);
            $entry = $zip->locateName(BackupService::SNAPSHOT_ENTRY);

            if ($entry === false) {
                throw new BackupException("The backup archive [{$backup->filename}] does not contain a database dump.");
            }

            $inner = $zip->getStream(BackupService::SNAPSHOT_ENTRY);

            if ($inner === false) {
                throw new BackupException("The database dump inside [{$backup->filename}] could not be read.");
            }

            $handle = @fopen($compressed, 'wb');
            stream_copy_to_stream($inner, $handle);
            fclose($inner);
            fclose($handle);
        }

        $sql = $workDirectory.DIRECTORY_SEPARATOR.'database.sql';
        $input = @gzopen($compressed, 'rb');
        $output = @fopen($sql, 'wb');

        if ($input === false || $output === false) {
            throw new BackupException('Unable to decompress the database dump.');
        }

        while (! gzeof($input)) {
            $chunk = gzread($input, 1024 * 1024);

            if ($chunk === false) {
                break;
            }

            fwrite($output, $chunk);
        }

        gzclose($input);
        fclose($output);

        if (filesize($sql) === 0) {
            throw new BackupException('The database dump inside the backup is empty.');
        }

        return $sql;
    }

    /**
     * Open a full backup archive for extraction.
     */
    protected function openArchive(Backup $backup, string $workDirectory): ZipArchive
    {
        $archive = $workDirectory.DIRECTORY_SEPARATOR.'backup.zip';
        $stream = Storage::disk($backup->disk)->readStream($backup->path);
        $handle = @fopen($archive, 'wb');

        if ($stream === false || $handle === false) {
            throw new BackupException("Unable to read the backup archive [{$backup->filename}].");
        }

        stream_copy_to_stream($stream, $handle);
        fclose($stream);
        fclose($handle);

        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new BackupException("The backup archive [{$backup->filename}] could not be opened.");
        }

        return $zip;
    }

    /**
     * Load the dump into the configured database with the mysql client.
     *
     * The client is used deliberately. Splitting a dump into statements in PHP
     * is unsafe around quoting, escapes and delimiters, and getting it wrong
     * means corrupting a live POS database. When the client is unavailable the
     * restore is refused with instructions instead.
     */
    protected function import(string $sqlPath): void
    {
        $config = config('database.connections.'.config('database.default'));

        if (($config['driver'] ?? null) !== 'mysql') {
            throw new BackupException('Only MySQL connections can be restored through the mysql client.');
        }

        $binary = $this->locator->findOrFail('mysql', 'database restores');
        $defaultsFile = $this->defaults->write($config, 'vfpr-');
        $handle = @fopen($sqlPath, 'rb');

        $command = [$binary, "--defaults-extra-file={$defaultsFile}", '--default-character-set=utf8mb4', '--binary-mode'];

        if (($config['unix_socket'] ?? '') !== '') {
            $command[] = "--socket={$config['unix_socket']}";
        } else {
            $command[] = "--host={$config['host']}";
            $command[] = "--port={$config['port']}";
        }

        $command[] = $config['database'];

        $process = (new Process($command, base_path()))
            ->setTimeout((int) config('backup.timeout', 3600));

        if (is_resource($handle)) {
            $process->setInput($handle);
        }

        try {
            $process->run();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $this->defaults->remove($defaultsFile);
        }

        if (! $process->isSuccessful()) {
            throw new BackupException(trim(
                'The mysql client exited with status '.$process->getExitCode().': '
                .(trim($process->getErrorOutput()) ?: 'no error output')
            ));
        }
    }

    /**
     * Read the first bytes of the stored artifact.
     */
    protected function readHead(Backup $backup, int $length): string
    {
        $stream = Storage::disk($backup->disk)->readStream($backup->path);

        if ($stream === false) {
            return '';
        }

        $head = (string) fread($stream, $length);
        fclose($stream);

        return $head;
    }

    /**
     * Force every user to sign in again after their session data was replaced.
     */
    protected function purgeSessions(): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->delete();
        }
    }

    /**
     * Block web traffic while the database is being replaced.
     */
    protected function enterMaintenanceMode(): void
    {
        $this->maintenanceMode()->activate([
            'message' => 'The database is being restored from a backup.',
            'retry' => 60,
            'secret' => null,
            'template' => null,
        ]);

        Log::critical('Application placed into maintenance mode for a restore.', [
            'disk' => config('backup.disk'),
        ]);
    }

    /**
     * Return the application to service.
     */
    protected function leaveMaintenanceMode(): void
    {
        $this->maintenanceMode()->deactivate();

        Log::info('Application returned to service after a restore.');
    }

    /**
     * The application maintenance mode.
     */
    protected function maintenanceMode(): MaintenanceMode
    {
        return app()->maintenanceMode();
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
