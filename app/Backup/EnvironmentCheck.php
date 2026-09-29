<?php

namespace App\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks whether this installation can actually take and restore backups.
 *
 * Almost every way backups fail on a new host is silent. A queue worker that
 * was never started leaves jobs waiting; a schedule that never runs means
 * nothing is ever queued in the first place; mysqldump missing quietly falls
 * back to a slower strategy; a full account quota produces a half written
 * artifact; a host that blocks proc_open makes the mysql client unusable. None
 * of these raise an error on the Backups screen, which is exactly why a new
 * deployment is the worst possible moment to find out.
 *
 * This runs every one of those checks up front so an operator can see what is
 * wrong before the first nightly backup, rather than discovering it days later.
 */
class EnvironmentCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * A completed backup older than this means the schedule is not running.
     */
    public const STALE_AFTER_HOURS = 30;

    /**
     * Privileges the mysql client needs to put a dump back.
     *
     * The dump carries "DROP TABLE IF EXISTS" for every table, so DROP, ALTER
     * and CREATE are all required. cPanel normally grants ALL PRIVILEGES on the
     * single database, which covers all of these, but a hand built grant often
     * omits DROP and a restore then fails halfway with a permission error.
     *
     * @var array<int, string>
     */
    protected const REQUIRED_PRIVILEGES = ['SELECT', 'INSERT', 'DROP', 'ALTER', 'CREATE'];

    /**
     * Every check, in the order they should be read.
     *
     * @return array<int, array{group: string, label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        $checks = [];

        foreach ([
            'PHP' => [$this->phpVersion(), $this->extensions(), $this->internationalisation(), $this->processFunctions(), $this->executionLimits()],
            'Binaries' => [$this->dumpBinary(), $this->clientBinary()],
            'Database' => [$this->databaseConnection(), $this->databasePrivileges(), $this->tables()],
            'Storage' => [$this->privateDisk(), $this->workDirectory(), $this->freeSpace()],
            'Queue' => [$this->queueConnection(), $this->queueBacklog(), $this->failedJobs()],
            'Schedule' => [$this->lastBackup(), $this->maintenanceMode()],
            'Application' => [$this->environment()],
        ] as $group => $results) {
            foreach ($results as $result) {
                $checks[] = ['group' => $group] + $result;
            }
        }

        return $checks;
    }

    /**
     * The number of checks that must be fixed before backups can be trusted.
     */
    public function failures(array $checks): int
    {
        return count(array_filter($checks, fn (array $check) => $check['status'] === self::FAIL));
    }

    /**
     * The number of checks worth knowing about.
     */
    public function warnings(array $checks): int
    {
        return count(array_filter($checks, fn (array $check) => $check['status'] === self::WARN));
    }

    protected function phpVersion(): array
    {
        $ok = PHP_VERSION_ID >= 80200;

        return $this->result('PHP version', $ok ? self::OK : self::FAIL, PHP_VERSION.($ok ? '' : ' — PHP 8.2 or newer is required'));
    }

    protected function extensions(): array
    {
        $missing = array_values(array_filter(
            ['zip', 'zlib', 'pdo_mysql', 'mbstring', 'openssl', 'fileinfo'],
            fn (string $extension) => ! extension_loaded($extension),
        ));

        return $this->result(
            'PHP extensions',
            $missing === [] ? self::OK : self::FAIL,
            $missing === [] ? 'zip, zlib, pdo_mysql, mbstring, openssl, fileinfo' : 'Missing: '.implode(', ', $missing).' — ask your host to enable them',
        );
    }

    protected function internationalisation(): array
    {
        if (extension_loaded('intl')) {
            return $this->result('intl extension', self::OK, 'Present');
        }

        return $this->result(
            'intl extension',
            self::WARN,
            'Not installed. Backups do not need it, but Laravel uses it for number and date formatting, so money and dates on the rest of the site may be shown in a fallback format.',
        );
    }

    protected function processFunctions(): array
    {
        $disabled = array_values(array_filter(
            ['proc_open', 'exec'],
            fn (string $function) => function_exists($function)
                ? in_array(strtolower($function), $this->disabledFunctions(), true)
                : true,
        ));

        if ($disabled !== []) {
            return $this->result(
                'Running the mysql client',
                self::FAIL,
                'The host blocks '.implode(', ', $disabled).'. Without it no backup can be taken by mysqldump and no restore is possible at all.',
            );
        }

        if (ini_get('open_basedir') !== '') {
            return $this->result(
                'Running the mysql client',
                self::WARN,
                'proc_open is allowed but open_basedir is set to '.ini_get('open_basedir').'. The mysql client must be inside that path to be reachable.',
            );
        }

        return $this->result('Running the mysql client', self::OK, 'proc_open is available');
    }

    protected function executionLimits(): array
    {
        $timeout = (int) config('backup.timeout', 3600);
        $limit = (int) ini_get('max_execution_time');

        // The CLI SAPI reports 0, which means no limit at all.
        $ok = $limit === 0 || $limit >= $timeout;

        return $this->result(
            'Job time limit',
            $ok ? self::OK : self::WARN,
            $limit === 0
                ? 'No PHP time limit; jobs may run for up to '.$timeout.'s'
                : ($ok
                    ? 'max_execution_time '.$limit.'s covers the '.$timeout.'s job timeout'
                    : 'max_execution_time is '.$limit.'s but a job may run for '.$timeout.'s. Raise the limit or lower BACKUP_TIMEOUT, or a long backup will be killed part way through.'),
        );
    }

    protected function dumpBinary(): array
    {
        $path = app(BinaryLocator::class)->find('mysqldump');

        if ($path === null) {
            return $this->result(
                'mysqldump',
                self::WARN,
                'Not found. Backups will fall back to a pure PHP snapshot, which works but is slower and holds the database open for longer. Set BACKUP_MYSQLDUMP_BINARY in .env to the full path to fix this.',
            );
        }

        return $this->result('mysqldump', self::OK, $path);
    }

    protected function clientBinary(): array
    {
        $path = app(BinaryLocator::class)->find('mysql');

        if ($path === null) {
            return $this->result(
                'mysql client (needed to restore)',
                self::FAIL,
                'Not found. Backups will still work, but a restore is impossible until this is available. Set BACKUP_MYSQL_BINARY in .env to the full path.',
            );
        }

        return $this->result('mysql client (needed to restore)', self::OK, $path);
    }

    protected function databaseConnection(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();

            return $this->result(
                'Database connection',
                self::OK,
                $connection->getDatabaseName().' on '.(config('database.connections.'.config('database.default').'.host') ?: 'local socket'),
            );
        } catch (Throwable $e) {
            return $this->result('Database connection', self::FAIL, $e->getMessage());
        }
    }

    protected function databasePrivileges(): array
    {
        try {
            $grants = collect(DB::select('SHOW GRANTS FOR CURRENT_USER()'))
                ->map(function (object $row): string {
                    $values = (array) $row;

                    return (string) reset($values);
                })
                ->implode(' ');
        } catch (Throwable $e) {
            return $this->result('Database privileges', self::WARN, 'Could not read the grant list: '.$e->getMessage());
        }

        if (stripos($grants, 'ALL PRIVILEGES') !== false) {
            return $this->result('Database privileges', self::OK, 'ALL PRIVILEGES granted — a restore can replace tables');
        }

        $missing = array_values(array_filter(
            self::REQUIRED_PRIVILEGES,
            fn (string $privilege) => stripos($grants, $privilege) === false,
        ));

        if ($missing === []) {
            return $this->result('Database privileges', self::OK, 'The privileges a restore needs are all granted');
        }

        return $this->result(
            'Database privileges',
            self::FAIL,
            'Missing '.implode(', ', $missing).'. A restore would fail part way through. Ask your host to grant these on this database.',
        );
    }

    protected function tables(): array
    {
        $missing = array_values(array_filter(
            ['backups', 'activity_logs', 'migrations', 'jobs'],
            fn (string $table) => ! Schema::hasTable($table),
        ));

        if ($missing !== []) {
            return $this->result(
                'Backup tables',
                self::FAIL,
                'Missing: '.implode(', ', $missing).' — run "php artisan migrate --force".',
            );
        }

        return $this->result('Backup tables', self::OK, 'backups, activity_logs, migrations and jobs all exist');
    }

    protected function privateDisk(): array
    {
        $disk = (string) config('backup.disk', 'local');
        $privateDisk = app(PrivateDisk::class);

        if (! $privateDisk->isDeclaredPrivate($disk)) {
            return $this->result(
                'Backup disk is private',
                self::FAIL,
                'The ['.$disk.'] disk is not listed in BACKUP_PRIVATE_DISKS, so nothing is written to it. Backups would be refused.',
            );
        }

        $path = app(DiskSpace::class)->backupDiskPath();

        if (! is_dir($path)) {
            return $this->result('Backup disk is private', self::FAIL, 'The directory ['.$path.'] does not exist. Create it and give the web server write access.');
        }

        if (! File::isWritable($path)) {
            return $this->result('Backup disk is private', self::FAIL, 'The directory ['.$path.'] is not writable by PHP. Fix the ownership or permissions.');
        }

        return $this->result('Backup disk is private', self::OK, $disk.' disk at '.$path);
    }

    protected function workDirectory(): array
    {
        $path = app(DiskSpace::class)->workPath();

        if (! is_dir($path)) {
            return $this->result('Staging directory', self::WARN, '['.$path.'] does not exist yet. It will be created on the first backup, so this is only a problem if the parent is not writable.');
        }

        if (! File::isWritable($path)) {
            return $this->result('Staging directory', self::FAIL, '['.$path.'] is not writable by PHP. This is where every dump is assembled before it is stored.');
        }

        return $this->result('Staging directory', self::OK, $path);
    }

    protected function freeSpace(): array
    {
        $space = app(DiskSpace::class);
        $required = $space->requiredBytes();
        $paths = [$space->backupDiskPath(), $space->workPath()];

        $reports = [];
        $short = false;

        foreach ($paths as $path) {
            $free = $space->freeBytesAt($path);

            if ($free === null) {
                $reports[] = basename($path).': unknown';

                continue;
            }

            $short = $short || $free < $required;
            $reports[] = basename($path).': '.DiskSpace::humanBytes($free).' free';
        }

        return $this->result(
            'Free disk space',
            $short ? self::FAIL : self::OK,
            implode(', ', $reports).'. Each job needs about '.DiskSpace::humanBytes($required).'.',
        );
    }

    protected function queueConnection(): array
    {
        $connection = (string) (config('backup.queue.connection') ?: config('queue.default', 'database'));

        if ($connection === 'sync') {
            return $this->result(
                'Queue connection',
                self::WARN,
                'sync — backups run inside the web request, so the browser waits and a long backup can time the request out. Use the database queue on a shared host.',
            );
        }

        if (! in_array($connection, ['database', 'redis'], true)) {
            return $this->result('Queue connection', self::WARN, '['.$connection.'] is unusual for a shared host. A "database" connection needs no extra services.');
        }

        $queues = array_filter([
            (string) config('backup.queue.name', 'backups'),
            (string) config('backup.queue.restore_name', 'restores'),
        ]);

        return $this->result(
            'Queue connection',
            self::OK,
            $connection.' — the worker must listen on: '.implode(', ', $queues),
        );
    }

    protected function queueBacklog(): array
    {
        if (app(QueueHealth::class)->isStalled()) {
            return $this->result(
                'Queued work',
                self::FAIL,
                'A backup or restore has been waiting longer than it should. The queue worker cron entry is missing, stopped, or listening on the wrong queue names.',
            );
        }

        return $this->result('Queued work', self::OK, 'Nothing is stuck waiting to run');
    }

    protected function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->result('Failed jobs', self::OK, 'No failed_jobs table');
        }

        $recent = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subWeek())
            ->count();

        if ($recent > 0) {
            return $this->result(
                'Failed jobs',
                self::FAIL,
                $recent.' job(s) failed in the last 7 days. Run "php artisan queue:failed" to see them.',
            );
        }

        return $this->result('Failed jobs', self::OK, 'No failures in the last 7 days');
    }

    protected function lastBackup(): array
    {
        if (! Schema::hasTable('backups')) {
            return $this->result(
                'Last completed backup',
                self::WARN,
                'There is no backups table yet. Run "php artisan migrate --force" and then take a backup by hand.',
            );
        }

        $latest = Backup::query()
            ->where('status', Backup::STATUS_COMPLETED)
            ->latest('id')
            ->first();

        if ($latest === null) {
            return $this->result(
                'Last completed backup',
                self::WARN,
                'No backup has ever completed here. Take one from the Backups screen, then run this again.',
            );
        }

        $age = (int) $latest->completed_at?->diffInHours(now());

        if ($age >= self::STALE_AFTER_HOURS) {
            return $this->result(
                'Last completed backup',
                self::FAIL,
                'The last one finished '.$age.' hours ago ('.$latest->filename.'). The schedule cron entry is probably not running — nothing is being queued at all.',
            );
        }

        return $this->result(
            'Last completed backup',
            self::OK,
            $latest->filename.' — '.$age.' hour(s) ago, '.$latest->humanSize(),
        );
    }

    protected function maintenanceMode(): array
    {
        if (app()->maintenanceMode()->active()) {
            return $this->result(
                'Maintenance mode',
                self::FAIL,
                'The site is currently down for everyone. If no restore is running, run "php artisan up".',
            );
        }

        return $this->result('Maintenance mode', self::OK, 'The site is up');
    }

    protected function environment(): array
    {
        if (app()->environment('production') === false) {
            return $this->result(
                'Environment',
                self::WARN,
                'APP_ENV is '.app()->environment().'. Production should run with APP_ENV=production and APP_DEBUG=false.',
            );
        }

        if (config('app.debug') === true) {
            return $this->result('Environment', self::FAIL, 'APP_DEBUG is true. Debug output can leak database values and file paths to anyone who triggers an error.');
        }

        return $this->result('Environment', self::OK, 'production with debugging off');
    }

    /**
     * The functions this host has disabled in php.ini.
     *
     * @return array<int, string>
     */
    protected function disabledFunctions(): array
    {
        return array_map(
            'trim',
            array_filter(explode(',', (string) ini_get('disable_functions'))),
        );
    }

    /**
     * Build a single check result.
     *
     * @return array{group: string, label: string, status: string, detail: string}
     */
    protected function result(string $label, string $status, string $detail): array
    {
        return ['group' => '', 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
