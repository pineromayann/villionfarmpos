<?php

use App\Backup\BackupException;
use App\Backup\BackupService;
use App\Backup\DiskSpace;
use App\Backup\EnvironmentCheck;
use App\Backup\FileArchiver;
use App\Backup\PrivateDefaultsFile;
use App\Backup\RestoreAccess;
use App\Backup\RestoreService;
use App\Models\Backup;
use App\Models\User;

beforeEach(function () {
    useBackupTestingDisk();
    app(RestoreAccess::class)->set(true);
});

/*
|--------------------------------------------------------------------------
| backup:doctor
|--------------------------------------------------------------------------
*/

test('backup:doctor reports on this installation', function () {
    $this->artisan('backup:doctor')
        ->expectsOutputToContain('mysqldump')
        ->expectsOutputToContain('mysql client (needed to restore)')
        ->expectsOutputToContain('Free disk space')
        ->expectsOutputToContain('Last completed backup')
        ->assertSuccessful();
});

test('backup:doctor warns rather than fails before the first backup is ever taken', function () {
    $check = collect(app(EnvironmentCheck::class)->checks())
        ->firstWhere('label', 'Last completed backup');

    expect($check['status'])->toBe(EnvironmentCheck::WARN)
        ->and($check['detail'])->toContain('No backup has ever completed');
});

test('backup:doctor fails when the schedule has stopped running', function () {
    Backup::factory()->create([
        'status' => Backup::STATUS_COMPLETED,
        'completed_at' => now()->subDays(3),
    ]);

    $check = collect(app(EnvironmentCheck::class)->checks())
        ->firstWhere('label', 'Last completed backup');

    expect($check['status'])->toBe(EnvironmentCheck::FAIL)
        ->and($check['detail'])->toContain('schedule cron entry is probably not running');
});

test('backup:doctor fails when a site is stuck in maintenance mode', function () {
    app()->maintenanceMode()->activate(['message' => 'test', 'retry' => 60]);

    try {
        $check = collect(app(EnvironmentCheck::class)->checks())
            ->firstWhere('label', 'Maintenance mode');

        expect($check['status'])->toBe(EnvironmentCheck::FAIL)
            ->and($check['detail'])->toContain('php artisan up');
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});

test('backup:doctor fails on a job that has been waiting too long', function () {
    // The suite runs the queue inline, and an inline queue is never stalled.
    config(['queue.default' => 'database', 'backup.queue.connection' => null]);

    DB::table('jobs')->insert([
        'queue' => config('backup.queue.name'),
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subHour()->getTimestamp(),
        'created_at' => now()->subHour()->getTimestamp(),
    ]);

    $check = collect(app(EnvironmentCheck::class)->checks())
        ->firstWhere('label', 'Queued work');

    expect($check['status'])->toBe(EnvironmentCheck::FAIL)
        ->and($check['detail'])->toContain('queue worker cron entry is missing');
});

/*
|--------------------------------------------------------------------------
| Free disk space
|--------------------------------------------------------------------------
*/

test('a backup is refused before it starts when the disk has no room', function () {
    fullDisk();

    $backup = app(BackupService::class)->request(Backup::TYPE_DATABASE);

    try {
        app(BackupService::class)->run($backup);

        $this->fail('The backup should have been refused for lack of space.');
    } catch (BackupException $e) {
        expect($e->getMessage())->toContain('not enough free disk space');
    }

    // The operator has to be able to see why it failed without reading logs.
    expect($backup->refresh()->status)->toBe(Backup::STATUS_FAILED)
        ->and($backup->error_message)->toContain('not enough free disk space');
});

test('a restore is refused for lack of space without taking the site down', function () {
    $admin = User::factory()->administrator()->create();
    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    config(['backup.restore.maintenance_mode' => true]);

    fullDisk();

    try {
        app(RestoreService::class)->restore($backup, $admin);

        $this->fail('The restore should have been refused for lack of space.');
    } catch (BackupException $e) {
        expect($e->getMessage())->toContain('not enough free disk space');
    }

    // The reason the check runs before maintenance mode is entered: a full
    // disk must never be what takes a production site offline.
    expect(app()->maintenanceMode()->active())->toBeFalse();
});

test('the space a job needs is sized from the last real backup', function () {
    expect(app(DiskSpace::class)->requiredBytes())->toBe(DiskSpace::MINIMUM_BYTES);

    Backup::factory()->create([
        'status' => Backup::STATUS_COMPLETED,
        'size' => 500 * 1024 * 1024,
    ]);

    expect(app(DiskSpace::class)->requiredBytes())->toBe(2 * (500 * 1024 * 1024));
});

test('byte counts are formatted without needing the intl extension', function () {
    expect(DiskSpace::humanBytes(0))->toBe('0 B')
        ->and(DiskSpace::humanBytes(2048))->toBe('2 KB')
        ->and(DiskSpace::humanBytes(1073741824))->toBe('1 GB')
        ->and(DiskSpace::humanBytes(1610612736))->toBe('1.5 GB');
});

test('free space is measured on the volume that will actually be written to', function () {
    $space = app(DiskSpace::class);

    expect($space->backupDiskPath())->not->toBeEmpty()
        ->and($space->freeBytesAt($space->workPath().'/not-created-yet'))->toBeInt()
        ->and($space->freeBytesAt(sys_get_temp_dir()))->toBeInt();
});

/*
|--------------------------------------------------------------------------
| Database credentials
|--------------------------------------------------------------------------
*/

test('the database credentials file is written inside private storage', function () {
    $file = app(PrivateDefaultsFile::class);

    $path = $file->write(['username' => 'pos_user', 'password' => 'p@ss word'], 'vfpw-');

    // Windows and Linux disagree about separators; the location is what matters.
    $slashes = fn (string $value) => str_replace('\\', '/', $value);

    try {
        expect($slashes($path))->toStartWith($slashes(app(FileArchiver::class)->workPath()))
            ->and($slashes($path))->not->toStartWith($slashes(sys_get_temp_dir()))
            ->and(file_get_contents($path))->toContain('user=pos_user')
            ->and(file_get_contents($path))->toContain('password=p@ss word');
    } finally {
        $file->remove($path);
    }
});

test('the credentials file is deleted once the job is finished', function () {
    $file = app(PrivateDefaultsFile::class);

    $path = $file->write(['username' => 'pos_user'], 'vfpw-');

    expect(is_file($path))->toBeTrue();

    $file->remove($path);

    expect(is_file($path))->toBeFalse();
});

test('removing a credentials file that is already gone is harmless', function () {
    app(PrivateDefaultsFile::class)->remove(storage_path('framework/testing/backup-work/not-here'));

    expect(true)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Report no free space at all, the way a full shared hosting quota does.
 */
function fullDisk(): void
{
    app()->instance(DiskSpace::class, new class(app(FileArchiver::class)) extends DiskSpace
    {
        public function freeBytesAt(string $path): ?int
        {
            return 1024;
        }
    });
}
