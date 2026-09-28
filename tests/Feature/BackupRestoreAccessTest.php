<?php

use App\Backup\BackupException;
use App\Backup\QueueHealth;
use App\Backup\RestoreAccess;
use App\Backup\RestoreService;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\BackupSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useBackupTestingDisk();
});

test('restoring starts switched off on a fresh install', function () {
    expect(app(RestoreAccess::class)->enabled())->toBeFalse()
        ->and(BackupSetting::query()->first()->restore_enabled)->toBeFalse();
});

test('the switch is stored in the database so no terminal is needed to change it', function () {
    $user = User::factory()->administrator()->create();

    app(RestoreAccess::class)->set(true, $user);

    // A brand new instance, as a later request would build, must agree.
    expect((new RestoreAccess)->enabled())->toBeTrue()
        ->and(BackupSetting::query()->first()->updated_by)->toBe($user->id);
});

test('an administrator can switch restoring on from the backups screen', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('backups.restore.access'), ['enabled' => 1])
        ->assertRedirect(route('backups.index'))
        ->assertSessionHas('success');

    expect(app(RestoreAccess::class)->enabled())->toBeTrue();

    $this->get(route('backups.index'))
        ->assertOk()
        ->assertSee('Restoring is now switched on')
        ->assertSee('Restoring is switched on');
});

test('an administrator can switch restoring back off again', function () {
    useRestorableBackups();

    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('backups.restore.access'), ['enabled' => 0])
        ->assertRedirect(route('backups.index'));

    expect(app(RestoreAccess::class)->enabled())->toBeFalse();

    $this->get(route('backups.index'))
        ->assertOk()
        ->assertSee('Restoring is now switched off');
});

test('switching restoring on and off is written to the audit log', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin);

    $this->post(route('backups.restore.access'), ['enabled' => 1]);
    $this->post(route('backups.restore.access'), ['enabled' => 0]);

    $entries = ActivityLog::query()
        ->where('action', ActivityLog::ACTION_RESTORE_ACCESS_CHANGED)
        ->orderBy('id')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->user_id)->toBe($admin->id)
        ->and($entries[0]->context)->toBe(['restore_enabled' => true])
        ->and($entries[1]->context)->toBe(['restore_enabled' => false]);
});

test('the restore button appears and disappears with the switch', function () {
    $admin = User::factory()->administrator()->create();
    $backup = Backup::factory()->create();

    $this->actingAs($admin);

    $this->get(route('backups.show', $backup))
        ->assertOk()
        ->assertDontSee('Restore from this backup')
        ->assertSee('Restoring is switched off');

    useRestorableBackups();

    $this->get(route('backups.show', $backup))
        ->assertOk()
        ->assertSee('Restore from this backup');
});

test('the restore confirmation screen is reachable only while the switch is on', function () {
    $admin = User::factory()->administrator()->create();
    $backup = Backup::factory()->create();

    $this->actingAs($admin);

    $this->get(route('backups.restore.confirm', $backup))->assertForbidden();

    useRestorableBackups();

    $this->get(route('backups.restore.confirm', $backup))->assertOk();
});

test('a manager cannot switch restoring on', function () {
    $this->actingAs(User::factory()->manager()->create())
        ->post(route('backups.restore.access'), ['enabled' => 1])
        ->assertForbidden();

    expect(app(RestoreAccess::class)->enabled())->toBeFalse();
});

test('the restore service refuses while the switch is off', function () {
    $backup = Backup::factory()->create();

    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'Restoring is switched off');
});

test('the restore service gets past the switch once it is on', function () {
    useRestorableBackups();

    config([
        'backup.restore.safety_backup' => false,
        'backup.restore.maintenance_mode' => false,
    ]);

    $payload = gzencode('SELECT 1;');
    $backup = Backup::factory()->create(['checksum' => hash('sha256', $payload)]);

    Storage::disk($backup->disk)->put($backup->path, $payload);

    // Reaching the MySQL-only refusal proves the switch let the restore through
    // and the failure is about the test database, not about access.
    expect(fn () => app(RestoreService::class)->restore($backup))
        ->toThrow(BackupException::class, 'Only MySQL connections can be restored');
});

test('the screen explains that a restore switches the site off and back on by itself', function () {
    useRestorableBackups();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.restore.confirm', Backup::factory()->create()))
        ->assertOk()
        ->assertSee('be right back')
        ->assertSee('do not need to do anything');
});

test('the backups screen warns when nothing is running the queue', function () {
    config(['queue.default' => 'database', 'backup.queue.stalled_after_minutes' => 5]);

    DB::table('jobs')->insert([
        'queue' => 'backups',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->addHour()->timestamp,
        'created_at' => now()->subMinutes(30),
    ]);

    expect(app(QueueHealth::class)->isStalled())->toBeTrue();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.index'))
        ->assertOk()
        ->assertSee('Backups are not running')
        ->assertSee('queue:work');
});

test('the screen stays quiet while a backup is starting normally', function () {
    config(['queue.default' => 'database', 'backup.queue.stalled_after_minutes' => 5]);

    DB::table('jobs')->insert([
        'queue' => 'backups',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->addHour()->timestamp,
        'created_at' => now()->subSecond(),
    ]);

    expect(app(QueueHealth::class)->isStalled())->toBeFalse();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.index'))
        ->assertOk()
        ->assertDontSee('Backups are not running');
});

test('the screen stays quiet about unrelated queues', function () {
    config(['queue.default' => 'database', 'backup.queue.stalled_after_minutes' => 5]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->addHour()->timestamp,
        'created_at' => now()->subDay(),
    ]);

    expect(app(QueueHealth::class)->isStalled())->toBeFalse();
});

test('the screen stays quiet when jobs run inside the request', function () {
    config([
        'queue.default' => 'sync',
        'backup.queue.connection' => 'sync',
        'backup.queue.stalled_after_minutes' => 5,
    ]);

    DB::table('jobs')->insert([
        'queue' => 'backups',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->addHour()->timestamp,
        'created_at' => now()->subDay(),
    ]);

    expect(app(QueueHealth::class)->isStalled())->toBeFalse();
});

test('the retention note explains the storage in plain language', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.index'))
        ->assertOk()
        ->assertSee('cannot be reached by guessing a web address')
        ->assertSee('Only a signed-in administrator can download one')
        ->assertDontSee('BACKUP_RESTORE_ENABLED');
});

test('nothing in the interface offers a command line restore', function () {
    useRestorableBackups();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.show', Backup::factory()->create()))
        ->assertOk()
        ->assertDontSee('backup:restore')
        ->assertDontSee('command line');
});

test('the backups screen admits when the disk is not private instead of claiming it is', function () {
    $admin = User::factory()->administrator()->create();

    config([
        'backup.disk' => 'public',
        'backup.private_disks' => ['local', 'public'],
    ]);

    $this->actingAs($admin)
        ->get(route('backups.index'))
        ->assertOk()
        ->assertSee('New backups are switched off.')
        ->assertDontSee('outside the folders the website can serve');
});

test('the backups screen keeps the reassuring copy when the disk is private', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('backups.index'))
        ->assertOk()
        ->assertSee('outside the folders the website can serve')
        ->assertDontSee('New backups are switched off.');
});
