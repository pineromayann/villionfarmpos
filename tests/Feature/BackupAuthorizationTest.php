<?php

use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    useBackupTestingDisk();
});

test('a guest cannot reach the backup list', function () {
    $this->get(route('backups.index'))->assertRedirect(route('login'));
});

test('a cashier cannot view, create, download, delete or restore backups', function () {
    $user = User::factory()->create(['role' => User::ROLE_CASHIER]);
    $backup = Backup::factory()->create();

    $this->actingAs($user);

    $this->get(route('backups.index'))->assertForbidden();
    $this->get(route('backups.show', $backup))->assertForbidden();
    $this->post(route('backups.store'), ['type' => Backup::TYPE_DATABASE])->assertForbidden();
    $this->get(route('backups.download', $backup))->assertForbidden();
    $this->delete(route('backups.destroy', $backup))->assertForbidden();
    $this->get(route('backups.restore.confirm', $backup))->assertForbidden();
    $this->post(route('backups.restore', $backup), ['confirmation' => $backup->filename])->assertForbidden();
});

test('inventory staff cannot view or create backups', function () {
    $user = User::factory()->inventoryStaff()->create();

    $this->actingAs($user);

    $this->get(route('backups.index'))->assertForbidden();
    $this->post(route('backups.store'), ['type' => Backup::TYPE_FULL])->assertForbidden();
});

test('a manager can view, create, download and delete backups but not restore them', function () {
    $manager = User::factory()->manager()->create();
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'placeholder');

    $this->actingAs($manager);

    $this->get(route('backups.index'))->assertOk()->assertSee($backup->filename);
    $this->get(route('backups.show', $backup))->assertOk();
    $this->get(route('backups.download', $backup))->assertOk();
    $this->get(route('backups.restore.confirm', $backup))->assertForbidden();
    $this->post(route('backups.restore', $backup), ['confirmation' => $backup->filename])->assertForbidden();
});

test('an administrator can reach every backup screen', function () {
    useRestorableBackups();

    $admin = User::factory()->administrator()->create();
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'placeholder');

    $this->actingAs($admin);

    $this->get(route('backups.index'))->assertOk();
    $this->get(route('backups.show', $backup))->assertOk();
    $this->get(route('backups.download', $backup))->assertOk();
    $this->get(route('backups.restore.confirm', $backup))->assertOk();
});

test('the backup detail screen reports what the backup captured', function () {
    $admin = User::factory()->administrator()->create();
    $backup = Backup::factory()->create([
        'snapshot' => ['driver' => 'mysqldump', 'tables' => 24, 'rows' => null],
        'included_paths' => ['dbjsons/settings.json'],
    ]);

    Storage::disk($backup->disk)->put($backup->path, 'placeholder');

    $this->actingAs($admin)
        ->get(route('backups.show', $backup))
        ->assertOk()
        ->assertSee('Database captured')
        ->assertSee('mysqldump')
        ->assertSee('24')
        // A driver that cannot count rows must not read as an empty backup.
        ->assertSee('not reported')
        ->assertSee('dbjsons/settings.json');
});

test('the backup page only appears in the navigation for users who can view backups', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $this->get(route('backups.index'))->assertOk()->assertSee('Backups');

    $this->actingAs(User::factory()->create(['role' => User::ROLE_CASHIER]));
    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('backups.index'));
});

test('a backup that has not completed cannot be downloaded', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin);

    $this->get(route('backups.download', Backup::factory()->pending()->create()))->assertForbidden();
    $this->get(route('backups.download', Backup::factory()->failed()->create()))->assertForbidden();
    $this->get(route('backups.download', Backup::factory()->running()->create()))->assertForbidden();
});

test('a backup that is still in flight cannot be deleted', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin);

    $this->delete(route('backups.destroy', Backup::factory()->running()->create()))->assertForbidden();
    $this->delete(route('backups.destroy', Backup::factory()->pending()->create()))->assertForbidden();
});

test('restoring is refused even for an administrator while it is disabled', function () {
    $admin = User::factory()->administrator()->create();
    $backup = Backup::factory()->create();

    $this->actingAs($admin);

    $this->get(route('backups.restore.confirm', $backup))->assertForbidden();
    $this->post(route('backups.restore', $backup), ['confirmation' => $backup->filename])->assertForbidden();
});

test('permissions are granted by role through the configured map', function () {
    $administrator = User::factory()->administrator()->create();
    $manager = User::factory()->manager()->create();
    $cashier = User::factory()->create();

    expect($administrator->hasPermission('backup.restore'))->toBeTrue()
        ->and($administrator->isAdministrator())->toBeTrue()
        ->and($manager->hasPermission('backup.create'))->toBeTrue()
        ->and($manager->hasPermission('backup.restore'))->toBeFalse()
        ->and($cashier->hasAnyPermission(['backup.view', 'backup.create']))->toBeFalse();
});

test('the restore section explains that a backup is not ready rather than blaming permissions', function (string $status, string $reason) {
    useRestorableBackups();

    $admin = User::factory()->administrator()->create();
    $backup = Backup::factory()->create(['status' => $status]);

    $this->actingAs($admin)
        ->get(route('backups.show', $backup))
        ->assertOk()
        ->assertSee($reason)
        ->assertDontSee('You do not have permission to restore backups.');
})->with([
    'waiting to start' => [Backup::STATUS_PENDING, 'still being made'],
    'part way through' => [Backup::STATUS_RUNNING, 'still being made'],
    'did not finish' => [Backup::STATUS_FAILED, 'did not finish'],
]);

test('the restore section blames permissions only when the permission is genuinely missing', function () {
    useRestorableBackups();

    $manager = User::factory()->manager()->create();
    $backup = Backup::factory()->create();

    $this->actingAs($manager)
        ->get(route('backups.show', $backup))
        ->assertOk()
        ->assertSee('You do not have permission to restore backups.');
});

test('the restore section reports the switch being off for a completed backup', function () {
    $admin = User::factory()->administrator()->create();
    $backup = Backup::factory()->create();

    $this->actingAs($admin)
        ->get(route('backups.show', $backup))
        ->assertOk()
        ->assertSee('Restoring is switched off')
        ->assertDontSee('You do not have permission to restore backups.');
});
