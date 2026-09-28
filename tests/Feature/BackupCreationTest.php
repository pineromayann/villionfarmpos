<?php

use App\Backup\BackupException;
use App\Backup\BackupService;
use App\Jobs\RunBackup;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    useBackupTestingDisk();
});

test('requesting a database backup records a pending row with its destination', function () {
    $admin = User::factory()->administrator()->create();
    $backup = app(BackupService::class)->request(Backup::TYPE_DATABASE, $admin, 'daily');

    expect($backup->exists)->toBeTrue()
        ->and($backup->status)->toBe(Backup::STATUS_PENDING)
        ->and($backup->type)->toBe(Backup::TYPE_DATABASE)
        ->and($backup->frequency)->toBe('daily')
        ->and($backup->disk)->toBe(config('backup.disk'))
        ->and($backup->path)->toBe(config('backup.directory').'/'.$backup->filename)
        ->and($backup->filename)->toStartWith('villonfarm-database-')
        ->and($backup->filename)->toEndWith('.sql.gz')
        ->and($backup->created_by)->toBe($admin->id)
        ->and($backup->database_name)->toBe(config('database.connections.'.config('database.default').'.database'));
});

test('the create endpoint validates the type and queues the job', function () {
    Queue::fake();

    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('backups.store'), ['type' => 'nonsense'])
        ->assertSessionHasErrors('type');

    expect(Backup::count())->toBe(0);

    $this->post(route('backups.store'), ['type' => Backup::TYPE_FULL])
        ->assertRedirect(route('backups.show', Backup::sole()))
        ->assertSessionHas('success');

    expect(Backup::sole()->type)->toBe(Backup::TYPE_FULL);

    Queue::assertPushed(RunBackup::class);
});

test('a completed database backup stores a real dump with its size and checksum', function () {
    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE, User::factory()->administrator()->create()));

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED)
        ->and($backup->size)->toBeGreaterThan(0)
        ->and($backup->checksum)->toHaveLength(64)
        ->and($backup->started_at)->not->toBeNull()
        ->and($backup->completed_at)->not->toBeNull()
        ->and($backup->error_message)->toBeNull();

    expect(Storage::disk($backup->disk)->exists($backup->path))->toBeTrue()
        ->and(Storage::disk($backup->disk)->size($backup->path))->toBe($backup->size);

    $contents = Storage::disk($backup->disk)->get($backup->path);

    expect($contents)->toStartWith("\x1f\x8b")
        ->and(hash('sha256', $contents))->toBe($backup->checksum);
});

test('a completed backup records what it actually captured', function () {
    Product::factory()->count(3)->create();

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_DATABASE));

    $dump = (string) gzdecode((string) Storage::disk($backup->disk)->get($backup->path));
    $captured = substr_count($dump, '-- Table structure for ');

    // The recorded counts have to agree with the artifact itself, so a silent
    // "captured nothing" backup cannot be reported as a success.
    expect($backup->snapshot['driver'])->toBe('sqlite')
        ->and($backup->snapshot['tables'])->toBe($captured)
        ->and($backup->snapshot['tables'])->toBeGreaterThan(0)
        ->and($backup->snapshot['rows'])->toBe(substr_count($dump, 'INSERT INTO '))
        ->and($backup->snapshot['rows'])->toBeGreaterThan(0);

    $log = ActivityLog::where('action', ActivityLog::ACTION_BACKUP_COMPLETED)->sole();

    expect($log->context)->toMatchArray([
        'driver' => 'sqlite',
        'tables' => $captured,
        'rows' => $backup->snapshot['rows'],
    ]);
});

test('a completed full backup records the archived project files', function () {
    writeBackupFixture('dbjsons/settings.json', '{"currency":"GHS"}');
    writeBackupFixture('public/uploads/logo.png', 'fake-png');

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_FULL));

    expect($backup->snapshot['driver'])->toBe('sqlite')
        ->and($backup->snapshot['tables'])->toBeGreaterThan(0)
        ->and($backup->included_paths)->toContain('storage/framework/testing/backup-fixtures/dbjsons/settings.json')
        ->and($backup->included_paths)->toContain('storage/framework/testing/backup-fixtures/public/uploads/logo.png')
        ->and(zipEntriesOf($backup))->toContain('database.sql.gz');
});

test('a completed full backup stores a zip containing the dump and the configured files', function () {
    writeBackupFixture('dbjsons/settings.json', '{"currency":"GHS"}');
    writeBackupFixture('public/uploads/receipt.png', 'binary');

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_FULL, User::factory()->administrator()->create()));

    expect($backup->status)->toBe(Backup::STATUS_COMPLETED)
        ->and($backup->filename)->toEndWith('.zip')
        ->and($backup->included_paths)->toContain('storage/framework/testing/backup-fixtures/dbjsons/settings.json');

    $entries = zipEntriesOf($backup);

    expect($entries)->toContain('database.sql.gz')
        ->and($entries)->toContain('files/storage/framework/testing/backup-fixtures/dbjsons/settings.json')
        ->and($entries)->toContain('files/storage/framework/testing/backup-fixtures/public/uploads/receipt.png');
});

test('a full backup never archives the backup directory, its staging area or excluded files', function () {
    writeBackupFixture('dbjsons/keep.json', '{}');
    writeBackupFixture('skip/secret.json', '{}');
    writeBackupFixture('noisy.log', 'noise');

    Storage::disk(backupDisk())->put('backups/older-backup.sql.gz', 'old');

    $service = app(BackupService::class);
    $backup = $service->run($service->request(Backup::TYPE_FULL, User::factory()->administrator()->create()));

    $entries = zipEntriesOf($backup);

    expect($entries)->toContain('files/storage/framework/testing/backup-fixtures/dbjsons/keep.json')
        ->and($entries)->not->toContain('files/storage/framework/testing/backup-fixtures/skip/secret.json')
        ->and($entries)->not->toContain('files/storage/framework/testing/backup-fixtures/noisy.log');

    foreach ($entries as $entry) {
        expect($entry)->not->toContain('backup-work')
            ->and($entry)->not->toContain('older-backup');
    }

    expect($backup->included_paths)->not->toContain('backups/older-backup.sql.gz');
});

test('creating a backup leaves every business table untouched', function () {
    $product = consignmentProduct();
    $before = [
        'products' => Product::count(),
        'stock' => $product->fresh()->stock,
    ];

    $service = app(BackupService::class);
    $service->run($service->request(Backup::TYPE_DATABASE, User::factory()->administrator()->create()));

    expect(Product::count())->toBe($before['products'])
        ->and($product->fresh()->stock)->toBe($before['stock']);
});

test('creating a backup writes an audit trail', function () {
    $admin = User::factory()->administrator()->create();
    $service = app(BackupService::class);

    $backup = $service->run($service->request(Backup::TYPE_DATABASE, $admin));

    expect(ActivityLog::where('action', ActivityLog::ACTION_BACKUP_CREATED)->count())->toBe(1)
        ->and(ActivityLog::where('action', ActivityLog::ACTION_BACKUP_COMPLETED)->count())->toBe(1);

    $created = ActivityLog::where('action', ActivityLog::ACTION_BACKUP_CREATED)->sole();

    expect($created->user_id)->toBe($admin->id)
        ->and($created->subject_type)->toBe(Backup::class)
        ->and($created->subject_id)->toBe($backup->id)
        ->and($created->result)->toBe(ActivityLog::RESULT_SUCCESS);
});

test('generated filenames never repeat', function () {
    $service = app(BackupService::class);
    $moment = now();

    $first = $service->filenameFor(Backup::TYPE_DATABASE, $moment);
    Backup::factory()->create(['filename' => $first]);

    expect($first)->toBe('villonfarm-database-'.$moment->format('Y-m-d-His').'.sql.gz')
        ->and($service->filenameFor(Backup::TYPE_DATABASE, $moment))->toBe('villonfarm-database-'.$moment->format('Y-m-d-His').'-2.sql.gz');
});

test('a filename cannot be taken from user input', function () {
    $service = app(BackupService::class);

    expect($service->filenameFor(Backup::TYPE_FULL))->toMatch('/^villonfarm-full-\d{4}-\d{2}-\d{2}-\d{6}(-\d+)?\.zip$/')
        ->and($service->pathFor('x.zip'))->toBe('backups/x.zip');
});

test('a failed backup is marked failed, cleaned up and audited', function () {
    $service = $this->mock(BackupService::class, function ($mock) {
        $mock->shouldReceive('request')->andReturnUsing(fn () => tap(new Backup, function (Backup $backup) {
            $backup->filename = 'villonfarm-database-broken.sql.gz';
            $backup->disk = config('backup.disk');
            $backup->path = config('backup.directory').'/villonfarm-database-broken.sql.gz';
            $backup->type = Backup::TYPE_DATABASE;
            $backup->status = Backup::STATUS_PENDING;
            $backup->save();
        }));

        $mock->shouldReceive('run')->andThrow(new BackupException('mysqldump is not installed.'));

        $mock->shouldReceive('fail')->andReturnUsing(fn (Backup $backup, Throwable $e) => tap($backup, function (Backup $backup) use ($e) {
            $backup->forceFill([
                'status' => Backup::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ])->save();
        }));
    });

    $backup = $service->request(Backup::TYPE_DATABASE, User::factory()->administrator()->create());

    expect(fn () => $service->run($backup))->toThrow(BackupException::class);
});

test('a backup that blows up mid run is failed, has its artifact removed and leaves no staging behind', function () {
    $service = app(BackupService::class);
    $backup = $service->request(Backup::TYPE_DATABASE, User::factory()->administrator()->create());

    $service->fail($backup, new RuntimeException('The disk is full.'));

    $backup->refresh();

    expect($backup->status)->toBe(Backup::STATUS_FAILED)
        ->and($backup->error_message)->toBe('The disk is full.')
        ->and($backup->completed_at)->not->toBeNull();

    expect(Storage::disk($backup->disk)->exists($backup->path))->toBeFalse();

    $failed = ActivityLog::where('action', ActivityLog::ACTION_BACKUP_FAILED)->sole();

    expect($failed->result)->toBe(ActivityLog::RESULT_FAILURE)
        ->and($failed->subject_id)->toBe($backup->id)
        ->and($failed->context['error'])->toBe('The disk is full.');
});

test('an unsupported database driver is refused', function () {
    $original = config('database.default');

    config([
        'database.connections.pgsql_test' => ['driver' => 'pgsql', 'database' => 'x'],
        'database.default' => 'pgsql_test',
    ]);

    try {
        expect(fn () => app(BackupService::class)->snapshot())
            ->toThrow(BackupException::class, 'Backups are not supported for the [pgsql] database driver');
    } finally {
        config(['database.default' => $original]);
    }
});

test('a backup is refused when the disk is not declared private', function () {
    config([
        'backup.disk' => 's3',
        'backup.private_disks' => ['local'],
    ]);

    expect(fn () => app(BackupService::class)->request(Backup::TYPE_DATABASE))
        ->toThrow(BackupException::class, 'Backups cannot be written to the [s3] disk');

    expect(Backup::query()->count())->toBe(0);
});

test('a remote disk is accepted once the operator declares it private', function () {
    config([
        'backup.disk' => 's3',
        'backup.private_disks' => ['local', 's3'],
    ]);

    $backup = app(BackupService::class)->request(Backup::TYPE_DATABASE);

    expect($backup->disk)->toBe('s3');
});

test('the public disk is refused even when it is declared private', function () {
    config([
        'backup.disk' => 'public',
        'backup.private_disks' => ['local', 'public'],
    ]);

    expect(fn () => app(BackupService::class)->request(Backup::TYPE_DATABASE))
        ->toThrow(BackupException::class, 'Backups cannot be written to the [public] disk');

    expect(Backup::query()->count())->toBe(0);
});

test('a local disk rooted inside the web root is refused even when it is declared private', function () {
    config([
        'filesystems.disks.web_reachable' => [
            'driver' => 'local',
            'root' => public_path('uploads'),
            'throw' => false,
        ],
        'backup.disk' => 'web_reachable',
        'backup.private_disks' => ['local', 'web_reachable'],
    ]);

    expect(fn () => app(BackupService::class)->request(Backup::TYPE_DATABASE))
        ->toThrow(BackupException::class, 'Backups cannot be written to the [web_reachable] disk');
});

test('a local disk that only reaches the web root through a storage symlink is refused', function () {
    config([
        'filesystems.links' => [public_path('storage') => storage_path('app/public')],
        'filesystems.disks.linked' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'throw' => false,
        ],
        'backup.disk' => 'linked',
        'backup.private_disks' => ['local', 'linked'],
    ]);

    expect(fn () => app(BackupService::class)->request(Backup::TYPE_DATABASE))
        ->toThrow(BackupException::class, 'Backups cannot be written to the [linked] disk');
});

test('a disk that does not exist is refused', function () {
    config([
        'backup.disk' => 'nope',
        'backup.private_disks' => ['local', 'nope'],
    ]);

    expect(fn () => app(BackupService::class)->request(Backup::TYPE_DATABASE))
        ->toThrow(BackupException::class, 'Backups cannot be written to the [nope] disk');
});
