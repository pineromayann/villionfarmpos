<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Produces a consistent MySQL snapshot with the mysqldump client.
 *
 * --single-transaction issues a single REPEATABLE READ read against InnoDB, so
 * the dump is internally consistent (a sale, receipt, stock movement, return
 * or consignment sale is captured whole or not at all) while writers keep
 * running. --quick streams rows so memory stays flat on large tables.
 *
 * The password is passed through a private defaults file rather than argv or
 * MYSQL_PWD so it never appears in the process list or the environment of
 * other users on the host.
 */
class MySqlDumpSnapshot implements DatabaseSnapshot
{
    /**
     * @param  array<int, string>  $excluded
     */
    public function __construct(
        protected BinaryLocator $locator,
        protected array $excluded = [],
    ) {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function dumpTo(string $target): array
    {
        $config = $this->connectionConfig();
        $binary = $this->locator->findOrFail('mysqldump', 'database backups');
        $tables = $this->includedTables();

        $defaultsFile = $this->writeDefaultsFile($config);
        $handle = @gzopen($target, 'wb9');

        if ($handle === false) {
            @unlink($defaultsFile);

            throw new BackupException("Unable to open the dump file for writing at [{$target}].");
        }

        $failure = null;

        try {
            $process = $this->process($binary, $config, $defaultsFile);

            $process->run(function (string $type, string $buffer) use ($handle): void {
                if ($type !== Process::OUT || $buffer === '') {
                    return;
                }

                if (@gzwrite($handle, $buffer) === false) {
                    throw new BackupException('Failed while writing the database dump to disk. Check free space on the backup disk.');
                }
            });

            if (! $process->isSuccessful()) {
                $failure = trim(
                    'mysqldump exited with status '.$process->getExitCode().': '
                    .(trim($process->getErrorOutput()) ?: 'no error output')
                );
            }
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        } finally {
            gzclose($handle);
            @unlink($defaultsFile);
        }

        if ($failure !== null) {
            @unlink($target);

            throw new BackupException($failure);
        }

        if (! is_file($target) || filesize($target) === 0) {
            throw new BackupException('mysqldump produced an empty dump file.');
        }

        // mysqldump does not report how many rows it wrote, so the count is
        // left null rather than recorded as a misleading zero.
        return ['driver' => 'mysqldump', 'tables' => $tables, 'rows' => null];
    }

    /**
     * The tables included in the dump, recorded on the backup row.
     *
     * @return array<int, string>
     */
    protected function includedTables(): array
    {
        $excluded = array_map('strtolower', $this->excluded);

        return array_values(array_filter(
            DB::getSchemaBuilder()->getTableListing(),
            fn (string $table) => ! in_array(strtolower($table), $excluded, true),
        ));
    }

    /**
     * The application database connection settings.
     *
     * @return array<string, mixed>
     */
    protected function connectionConfig(): array
    {
        $config = config('database.connections.'.config('database.default'));

        if (! is_array($config) || ($config['driver'] ?? null) !== 'mysql') {
            throw new BackupException('mysqldump can only be used with a mysql connection.');
        }

        return $config;
    }

    /**
     * Build the mysqldump invocation.
     *
     * @param  array<string, mixed>  $config
     */
    protected function process(string $binary, array $config, string $defaultsFile): Process
    {
        $command = [
            $binary,
            "--defaults-extra-file={$defaultsFile}",
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--no-tablespaces',
            '--add-drop-table',
            '--set-gtid-purged=OFF',
            '--default-character-set=utf8mb4',
            '--skip-lock-tables',
            '--skip-comments',
            '--hex-blob',
        ];

        if (($config['unix_socket'] ?? '') !== '') {
            $command[] = "--socket={$config['unix_socket']}";
        } else {
            $command[] = "--host={$config['host']}";
            $command[] = "--port={$config['port']}";
        }

        $command[] = $config['database'];

        foreach ($this->excluded as $table) {
            $table = trim((string) $table);

            if ($table !== '') {
                $command[] = '--ignore-table='.$config['database'].'.'.$table;
            }
        }

        return (new Process($command, base_path()))->setTimeout((int) config('backup.timeout', 3600));
    }

    /**
     * Write a private defaults file carrying the database credentials.
     *
     * The user is always written, even when the account is passwordless, so
     * the dump never silently connects as a different account.
     *
     * @param  array<string, mixed>  $config
     * @return string Absolute path of the created file.
     */
    protected function writeDefaultsFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vfpw-');

        if ($path === false) {
            throw new BackupException('Unable to create a temporary file for the database credentials.');
        }

        $contents = "[client]\n";

        if (($config['username'] ?? '') !== '') {
            $contents .= 'user='.$config['username']."\n";
        }

        if (($config['password'] ?? '') !== '') {
            $contents .= 'password='.str_replace(["\r", "\n", '"'], ['', '', '\\"'], $config['password'])."\n";
        }

        file_put_contents($path, $contents);
        @chmod($path, 0600);

        return $path;
    }
}
