<?php

use App\Backup\BackupException;
use App\Backup\FileArchiver;
use App\Backup\RestoreService;
use App\Jobs\RunRestore;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

beforeEach(function () {
    useBackupTestingDisk();
});

/**
 * Store a real, checksummed artifact for a backup row.
 */
function storeArtifact(Backup $backup, string $contents): Backup
{
    Storage::disk($backup->disk)->put($backup->path, $contents);
    $backup->forceFill(['checksum' => hash('sha256', $contents)])->save();

    return $backup;
}

test('a backup that has not completed cannot be restored', function () {
    useRestorableBackups();

    foreach (['pending', 'running', 'failed'] as $status) {
        $backup = Backup::factory()->{$status}()->create();

        expect(fn () => app(RestoreService::class)->restore($backup))
            ->toThrow(BackupException::class, 'is not a completed backup');
    }
});

test('the unavailable page explains why the application is down', function () {
    $manager = app()->maintenanceMode();

    $manager->activate([
        'message' => 'The database is being restored from a backup.',
        'retry' => 60,
    ]);

    try {
        $this->get('/')
            ->assertStatus(503)
            ->assertSee('The database is being restored from a backup.')
            ->assertSee('php artisan up')
            ->assertDontSee('We will be right back');
    } finally {
        $manager->deactivate();
    }
});

test('the unavailable page falls back to a generic message when there is no reason', function () {
    $rendered = view('errors.503', [
        'exception' => new ServiceUnavailableHttpException,
    ])->render();

    expect($rendered)->toContain('We will be right back')
        ->and($rendered)->not->toContain('php artisan up');
});

test('a restore is refused while the feature is switched off', function () {
    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'Restoring is switched off');
});

test('a restore is refused when the artifact is gone', function () {
    useRestorableBackups();

    $backup = Backup::factory()->create();

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'no longer present');
});

test('a restore is refused when the artifact is empty', function () {
    useRestorableBackups();

    $backup = storeArtifact(Backup::factory()->create(), '');

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'empty or unreadable');
});

test('a restore is refused when the artifact is not the file type it claims to be', function () {
    useRestorableBackups();

    $database = storeArtifact(Backup::factory()->create(), 'this is plain text, not a gzip stream');
    $full = storeArtifact(Backup::factory()->full()->create(), 'this is plain text, not a zip');

    expect(fn () => app(RestoreService::class)->restore($database))
        ->toThrow(BackupException::class, 'does not look like a valid gzipped SQL dump');

    expect(fn () => app(RestoreService::class)->restore($full))
        ->toThrow(BackupException::class, 'does not look like a valid zip archive');
});

test('a restore is refused when the checksum does not match', function () {
    useRestorableBackups();

    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, gzencode('SELECT 1;'));

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'is corrupt');
});

test('a restore is refused when the recorded path escapes the backup directory', function () {
    useRestorableBackups();

    $backup = Backup::factory()->create(['path' => '../../.env']);

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'no longer present');
});

test('a restore is refused when a full backup archive has no database dump in it', function () {
    useRestorableBackups();

    $path = tempnam(sys_get_temp_dir(), 'vfpz-');

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('files/dbjsons/settings.json', '{}');
    $zip->close();

    $backup = storeArtifact(Backup::factory()->full()->create(), (string) file_get_contents($path));

    unlink($path);

    config([
        'backup.restore.safety_backup' => false,
        'backup.restore.maintenance_mode' => false,
    ]);

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'does not contain a database dump');
});

test('a full backup with a traversal entry in the archive cannot escape the project', function () {
    $path = tempnam(sys_get_temp_dir(), 'vfpz-');

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('database.sql.gz', gzencode('SELECT 1;'));
    $zip->addFromString('files/../../escaped.txt', 'nope');
    $zip->close();

    $staging = storage_path('framework/testing/backup-work/traversal');

    $archive = new ZipArchive;
    $archive->open($path);

    $written = app(FileArchiver::class)->stage($archive, $staging);
    $archive->close();

    unlink($path);

    expect($written)->toBe([])
        ->and(file_exists(base_path('escaped.txt')))->toBeFalse()
        ->and(file_exists(dirname(base_path()).DIRECTORY_SEPARATOR.'escaped.txt'))->toBeFalse();
});

test('a staged full backup is only written into the project on commit', function () {
    $path = tempnam(sys_get_temp_dir(), 'vfpz-');

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('database.sql.gz', gzencode('SELECT 1;'));
    $zip->addFromString('files/storage/framework/testing/backup-fixtures/dbjsons/settings.json', '{"currency":"GHS"}');
    $zip->close();

    $archive = new ZipArchive;
    $archive->open($path);

    $archiver = app(FileArchiver::class);
    $staging = storage_path('framework/testing/backup-work/staged');
    $staged = $archiver->stage($archive, $staging);

    $archive->close();

    $relative = 'storage/framework/testing/backup-fixtures/dbjsons/settings.json';

    expect($staged)->toHaveKey($relative)
        ->and(file_exists(base_path($relative)))->toBeFalse()
        ->and(file_get_contents($staged[$relative]))->toContain('currency');

    expect($archiver->commit($staged))->toBe([$relative])
        ->and(file_get_contents(base_path($relative)))->toContain('currency');
});

test('the restore endpoint requires the exact filename to be typed', function () {
    useRestorableBackups();

    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('backups.restore', $backup), ['confirmation' => 'wrong.sql.gz'])
        ->assertSessionHasErrors('confirmation');

    Queue::fake();
    Queue::assertNothingPushed();
});

test('the restore endpoint queues the restore for an administrator', function () {
    useRestorableBackups();
    Queue::fake();

    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('backups.restore', $backup), ['confirmation' => $backup->filename])
        ->assertRedirect(route('backups.show', $backup))
        ->assertSessionHas('success');

    Queue::assertPushed(RunRestore::class, fn (RunRestore $job) => $job->backup->id === $backup->id
        && $job->queue === 'restores'
        && $job->user?->hasPermission('backup.restore'));
});

test('a manager cannot start a restore from the web', function () {
    useRestorableBackups();
    Queue::fake();

    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    $this->actingAs(User::factory()->manager()->create())
        ->post(route('backups.restore', $backup), ['confirmation' => $backup->filename])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('the restore confirmation screen warns about what is overwritten', function () {
    useRestorableBackups();

    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.restore.confirm', $backup))
        ->assertOk()
        ->assertSee('This will overwrite the live POS and inventory data')
        ->assertSee('A safety backup of the')
        ->assertSee('Type the backup filename to confirm')
        ->assertSee($backup->filename);
});

test('a restore that fails is audited as a failure', function () {
    useRestorableBackups();

    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    config([
        'backup.restore.safety_backup' => false,
        'backup.restore.maintenance_mode' => false,
    ]);

    expect(fn () => app(RestoreService::class)->restore($backup, User::factory()->administrator()->create()))
        ->toThrow(BackupException::class);

    $failed = ActivityLog::where('action', ActivityLog::ACTION_RESTORE_FAILED)->sole();

    expect($failed->result)->toBe(ActivityLog::RESULT_FAILURE)
        ->and($failed->subject_id)->toBe($backup->id)
        ->and($failed->context['error'])->toContain('Only MySQL connections');

    expect(ActivityLog::where('action', ActivityLog::ACTION_RESTORE_STARTED)->count())->toBeGreaterThan(0);
});

test('a restore leaves no backup row stuck in a running state', function () {
    useRestorableBackups();

    // A backup row is written while its own dump is still being produced, so
    // the copy inside the artifact always looks like it is still running.
    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    Backup::factory()->pending()->create();
    Backup::factory()->running()->create();

    config([
        'backup.restore.safety_backup' => false,
        'backup.restore.maintenance_mode' => false,
    ]);

    expect(fn () => app(RestoreService::class)->restore($backup))->toThrow(BackupException::class);

    expect(Backup::whereIn('status', [Backup::STATUS_PENDING, Backup::STATUS_RUNNING])->count())->toBe(0)
        ->and(Backup::where('status', Backup::STATUS_FAILED)->count())->toBe(2)
        ->and(Backup::where('status', Backup::STATUS_FAILED)->pluck('error_message')->unique()->all())
        ->toBe(['Interrupted: this backup was still running when the database was restored.']);
});

test('a restore lock is released even when validation fails', function () {
    useRestorableBackups();

    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    // The first restore fails on the import, deep inside the try block.
    config([
        'backup.restore.safety_backup' => false,
        'backup.restore.maintenance_mode' => false,
    ]);

    expect(fn () => app(RestoreService::class)->restore($backup))->toThrow(BackupException::class);

    // A second attempt must be possible; a leaked lock would block the operator
    // from ever trying again in the same process.
    expect(fn () => app(RestoreService::class)->restore($backup))->toThrow(BackupException::class, 'Only MySQL connections');
});

test('a second restore cannot start while one is already running', function () {
    useRestorableBackups();

    $backup = storeArtifact(Backup::factory()->create(), gzencode('SELECT 1;'));

    config([
        'backup.restore.safety_backup' => false,
        'backup.restore.maintenance_mode' => false,
    ]);

    Cache::lock('backup:restore', 60)->get();

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'Another restore is already running');
});
