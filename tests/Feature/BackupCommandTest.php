<?php

use App\Backup\BackupException;
use App\Backup\RestoreService;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    useBackupTestingDisk();
});

test('backup:create runs a database backup inline with --sync', function () {
    $this->artisan('backup:create', ['--database' => true, '--sync' => true])->assertSuccessful();

    $backup = Backup::sole();

    expect($backup->type)->toBe(Backup::TYPE_DATABASE)
        ->and($backup->status)->toBe(Backup::STATUS_COMPLETED)
        ->and($backup->frequency)->toBe('daily');

    expect(Storage::disk($backup->disk)->exists($backup->path))->toBeTrue();
});

test('backup:create runs a full backup with --full', function () {
    $this->artisan('backup:create', ['--full' => true, '--sync' => true])->assertSuccessful();

    $backup = Backup::sole();

    expect($backup->type)->toBe(Backup::TYPE_FULL)
        ->and($backup->status)->toBe(Backup::STATUS_COMPLETED);

    expect(Storage::disk($backup->disk)->get($backup->path))->toStartWith("PK\x03\x04");
});

test('backup:create records the retention frequency it was given', function () {
    $this->artisan('backup:create', ['--database' => true, '--frequency' => 'monthly', '--sync' => true])
        ->assertSuccessful();

    expect(Backup::sole()->frequency)->toBe('monthly');
});

test('backup:create keeps a manual backup forever when the frequency is none', function () {
    $this->artisan('backup:create', ['--database' => true, '--frequency' => 'none', '--sync' => true])
        ->assertSuccessful();

    expect(Backup::sole()->frequency)->toBeNull();
});

test('backup:create attributes the backup to the given user', function () {
    $admin = User::factory()->administrator()->create();

    $this->artisan('backup:create', [
        '--database' => true,
        '--sync' => true,
        '--user' => $admin->id,
    ])->assertSuccessful();

    expect(Backup::sole()->created_by)->toBe($admin->id);
});

test('backup:create dispatches a job when the queue is asynchronous', function () {
    Queue::fake();
    config(['queue.default' => 'database']);

    $this->artisan('backup:create', ['--database' => true])->assertSuccessful();

    expect(Backup::sole()->status)->toBe(Backup::STATUS_PENDING);

    Queue::assertPushed(RunBackup::class);
});

test('backup:create refuses an unknown frequency', function () {
    $this->artisan('backup:create', ['--frequency' => 'yearly'])
        ->expectsOutputToContain('Unknown frequency')
        ->assertFailed();

    expect(Backup::count())->toBe(0);
});

test('backup:create refuses an unknown user', function () {
    $this->artisan('backup:create', ['--sync' => true, '--user' => 9999])
        ->expectsOutputToContain('No user exists with the id [9999]')
        ->assertFailed();

    expect(Backup::count())->toBe(0);
});

test('backup:create reports a failure without leaving an artifact behind', function () {
    $blocker = tempnam(sys_get_temp_dir(), 'vfpw-');
    config(['backup.work_directory' => $blocker]);

    $this->artisan('backup:create', ['--database' => true, '--sync' => true])->assertFailed();

    $backup = Backup::sole();

    expect($backup->status)->toBe(Backup::STATUS_FAILED)
        ->and($backup->error_message)->toContain('Unable to create the directory')
        ->and(Storage::disk(backupDisk())->exists($backup->path))->toBeFalse();

    unlink($blocker);
});

test('backup:list prints the known backups', function () {
    Backup::factory()->create(['filename' => 'villonfarm-database-2026-01-01-020000.sql.gz']);

    $this->artisan('backup:list')->assertSuccessful();
});

test('backup:list reports an empty archive clearly', function () {
    $this->artisan('backup:list')
        ->expectsOutputToContain('No backups')
        ->assertSuccessful();
});

test('there is no command line restore', function () {
    expect(array_keys(Artisan::all()))
        ->not->toContain('backup:restore');
});

test('a failed restore leaves the application in maintenance mode for a human to inspect', function () {
    useRestorableBackups();

    $payload = gzencode('SELECT 1;');
    $backup = Backup::factory()->create(['checksum' => hash('sha256', $payload)]);

    Storage::disk($backup->disk)->put($backup->path, $payload);

    config([
        'backup.restore.safety_backup' => false,
        'backup.restore.maintenance_mode' => true,
    ]);

    $manager = app()->maintenanceMode();

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class);

    expect($manager->active())->toBeTrue();

    $manager->deactivate();

    expect($manager->active())->toBeFalse();
});

test('backup:cleanup reports how many backups it removed', function () {
    config(['backup.retention' => ['daily' => 1]]);

    Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()->subDays(4)]);
    Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()]);

    $this->artisan('backup:cleanup')->expectsOutputToContain('1')->assertSuccessful();

    expect(Backup::count())->toBe(1);
});

test('the restore service refuses to run twice at the same time', function () {
    useRestorableBackups();

    $backup = Backup::factory()->create();
    $service = app(RestoreService::class);

    $held = Cache::lock('backup:restore', 60);
    $held->get();

    expect(fn () => $service->restore($backup))->toThrow(BackupException::class, 'Another restore is already running');

    $held->release();
});
