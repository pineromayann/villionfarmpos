<?php

use App\Backup\BackupService;
use App\Backup\RetentionService;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    useBackupTestingDisk();
});

test('deleting a backup removes both the record and the artifact', function () {
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'payload');

    $this->actingAs(User::factory()->administrator()->create())
        ->delete(route('backups.destroy', $backup))
        ->assertRedirect(route('backups.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('backups', ['id' => $backup->id]);

    expect(Storage::disk($backup->disk)->exists($backup->path))->toBeFalse();
});

test('deleting a backup is recorded in the audit log', function () {
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'payload');

    $this->actingAs(User::factory()->manager()->create())
        ->delete(route('backups.destroy', $backup))
        ->assertRedirect();

    $entry = ActivityLog::where('action', ActivityLog::ACTION_BACKUP_DELETED)->sole();

    expect($entry->context['filename'])->toBe($backup->filename)
        ->and($entry->context['created_by'])->not->toBeNull();
});

test('a cashier cannot delete a backup', function () {
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'payload');

    $this->actingAs(User::factory()->create(['role' => User::ROLE_CASHIER]))
        ->delete(route('backups.destroy', $backup))
        ->assertForbidden();

    $this->assertDatabaseHas('backups', ['id' => $backup->id]);

    expect(Storage::disk($backup->disk)->exists($backup->path))->toBeTrue();
});

test('cleanup keeps the configured number of recent backups per frequency', function () {
    config(['backup.retention' => ['daily' => 2, 'weekly' => 1, 'monthly' => 1]]);

    $daily = Backup::factory()->count(4)->sequence(fn ($sequence) => [
        'frequency' => 'daily',
        'created_at' => now()->subDays(10 - $sequence->index),
    ])->create();

    $weekly = Backup::factory()->count(3)->sequence(fn ($sequence) => [
        'frequency' => 'weekly',
        'created_at' => now()->subWeeks(5 - $sequence->index),
    ])->create();

    $result = app(RetentionService::class)->prune();

    expect($result['deleted'])->toHaveCount(4)
        ->and($result['retained'])->toHaveCount(3)
        ->and(Backup::completed()->frequency('daily')->count())->toBe(2)
        ->and(Backup::completed()->frequency('weekly')->count())->toBe(1);

    expect(Backup::pluck('id')->sort()->values()->all())->toBe(
        $daily->slice(2)->pluck('id')->merge($weekly->slice(2)->pluck('id'))->sort()->values()->all()
    );
});

test('cleanup removes the artifact of every backup it deletes', function () {
    config(['backup.retention' => ['daily' => 1]]);

    $old = Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()->subDays(9)]);
    Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()]);

    Storage::disk($old->disk)->put($old->path, 'old');

    app(RetentionService::class)->prune();

    $this->assertDatabaseMissing('backups', ['id' => $old->id]);

    expect(Storage::disk($old->disk)->exists($old->path))->toBeFalse();
});

test('cleanup never deletes a backup that is pending or running', function () {
    config(['backup.retention' => ['daily' => 1]]);

    $pending = Backup::factory()->pending()->create(['frequency' => 'daily', 'created_at' => now()->subMonth()]);
    $running = Backup::factory()->running()->create(['frequency' => 'daily', 'created_at' => now()->subMonth()]);
    Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()]);

    $result = app(RetentionService::class)->prune();

    expect($result['deleted'])->toBeEmpty();

    $this->assertDatabaseHas('backups', ['id' => $pending->id]);
    $this->assertDatabaseHas('backups', ['id' => $running->id]);
});

test('cleanup never deletes an ungrouped manual backup', function () {
    config(['backup.retention' => ['daily' => 0]]);

    $manual = Backup::factory()->create(['frequency' => null, 'created_at' => now()->subYears(2)]);

    app(RetentionService::class)->prune();

    $this->assertDatabaseHas('backups', ['id' => $manual->id]);
});

test('cleanup in pretend mode reports what it would delete without deleting it', function () {
    config(['backup.retention' => ['daily' => 1]]);

    $old = Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()->subDays(5)]);
    Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()]);

    $result = app(RetentionService::class)->prune(pretend: true);

    expect($result['deleted'])->toBe([$old->filename])
        ->and($result['retained'])->toBe([Backup::latest('id')->value('filename')])
        ->and(Backup::count())->toBe(2);

    $this->assertDatabaseHas('backups', ['id' => $old->id]);
});

test('the cleanup command honours the pretend flag', function () {
    config(['backup.retention' => ['daily' => 1]]);

    $backup = Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()->subDays(5)]);
    Backup::factory()->create(['frequency' => 'daily', 'created_at' => now()]);

    $this->artisan('backup:cleanup', ['--pretend' => true])->assertSuccessful();

    $this->assertDatabaseHas('backups', ['id' => $backup->id]);

    $this->artisan('backup:cleanup')->assertSuccessful();

    $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
});

test('forgetting a backup through the service leaves nothing behind', function () {
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'payload');

    app(BackupService::class)->forget($backup);

    $this->assertDatabaseMissing('backups', ['id' => $backup->id]);

    expect(Storage::disk($backup->disk)->exists($backup->path))->toBeFalse();
});
