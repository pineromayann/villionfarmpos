<?php

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    useBackupTestingDisk();
});

test('an administrator can download the stored artifact', function () {
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'the-real-dump');

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.download', $backup))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename='.$backup->filename);
});

test('a manager can download the stored artifact', function () {
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'the-real-dump');

    $this->actingAs(User::factory()->manager()->create())
        ->get(route('backups.download', $backup))
        ->assertOk();
});

test('downloading a backup is recorded in the audit log', function () {
    $admin = User::factory()->administrator()->create();
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put($backup->path, 'the-real-dump');

    $this->actingAs($admin)->get(route('backups.download', $backup))->assertOk();

    $entry = ActivityLog::where('action', ActivityLog::ACTION_BACKUP_DOWNLOADED)->sole();

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($backup->id)
        ->and($entry->result)->toBe(ActivityLog::RESULT_SUCCESS)
        ->and($entry->context['size'])->toBe($backup->size);
});

test('a download whose file has gone missing returns not found and is audited as a failure', function () {
    $backup = Backup::factory()->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.download', $backup))
        ->assertNotFound();

    $entry = ActivityLog::where('action', ActivityLog::ACTION_BACKUP_DOWNLOADED)->sole();

    expect($entry->result)->toBe(ActivityLog::RESULT_FAILURE)
        ->and($entry->context['reason'])->toBe('missing_file');
});

test('the download never reads outside the backup directory', function () {
    $backup = Backup::factory()->create();

    Storage::disk($backup->disk)->put('.env', 'SECRET=value');
    $backup->update(['path' => '../.env']);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('backups.download', $backup))
        ->assertNotFound();
});
