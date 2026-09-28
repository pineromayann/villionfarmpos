<?php

namespace App\Http\Controllers;

use App\Backup\ActivityLogger;
use App\Backup\BackupService;
use App\Backup\PrivateDisk;
use App\Backup\QueueHealth;
use App\Backup\RestoreAccess;
use App\Backup\RestoreService;
use App\Jobs\RunBackup;
use App\Jobs\RunRestore;
use App\Models\ActivityLog;
use App\Models\Backup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function __construct(
        protected BackupService $backups,
        protected RestoreService $restores,
        protected RestoreAccess $access,
        protected QueueHealth $queue,
        protected PrivateDisk $privateDisk,
    ) {
        //
    }

    /**
     * Show the backup management page.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Backup::class);

        $backups = Backup::with('creator')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $summary = Backup::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $retention = (array) config('backup.retention', []);

        return view('backups.index', [
            'backups' => $backups,
            'summary' => $summary,
            'statuses' => (array) config('backup.statuses', []),
            'types' => [Backup::TYPE_DATABASE, Backup::TYPE_FULL],
            'retention' => $retention,
            'disk' => config('backup.disk'),
            'diskIsPrivate' => $this->privateDisk->isDeclaredPrivate((string) config('backup.disk')),
            'restoreEnabled' => $this->access->enabled(),
            'queueStalled' => $this->queue->isStalled(),
        ]);
    }

    /**
     * Show the details of a single backup.
     */
    public function show(Backup $backup): View
    {
        $this->authorize('view', $backup);

        return view('backups.show', [
            'backup' => $backup->load('creator'),
            'activity' => ActivityLog::forSubject($backup)->latest('id')->limit(50)->get(),
            'restoreEnabled' => $this->access->enabled(),
        ]);
    }

    /**
     * Queue a new backup.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Backup::class);

        $validated = $request->validate([
            'type' => ['required', Rule::in([Backup::TYPE_DATABASE, Backup::TYPE_FULL])],
            'frequency' => ['nullable', Rule::in([...Backup::FREQUENCIES, 'none'])],
        ]);

        $frequency = ($validated['frequency'] ?? 'none') === 'none'
            ? null
            : $validated['frequency'];

        $backup = $this->backups->request(
            $validated['type'],
            $request->user(),
            $frequency,
        );

        RunBackup::dispatch($backup);

        return redirect()
            ->route('backups.show', $backup)
            ->with('success', "Backup {$backup->filename} has been queued and will start shortly.");
    }

    /**
     * Stream a backup file to the browser.
     */
    public function download(Request $request, Backup $backup): Response|StreamedResponse
    {
        $this->authorize('download', $backup);

        $disk = Storage::disk($backup->disk);

        if (! $backup->hasSafePath() || ! $disk->exists($backup->path)) {
            ActivityLogger::record(ActivityLog::ACTION_BACKUP_DOWNLOADED, $backup, [
                'user' => $request->user(),
                'result' => ActivityLog::RESULT_FAILURE,
                'context' => ['reason' => 'missing_file'],
            ]);

            return response("The backup file is no longer available on the [{$backup->disk}] disk.", 404);
        }

        ActivityLogger::record(ActivityLog::ACTION_BACKUP_DOWNLOADED, $backup, [
            'user' => $request->user(),
            'context' => ['size' => $backup->size],
        ]);

        return $disk->download($backup->path, $backup->filename);
    }

    /**
     * Delete a backup and its file.
     */
    public function destroy(Request $request, Backup $backup): RedirectResponse
    {
        $this->authorize('delete', $backup);

        $filename = $backup->filename;
        $creator = $backup->creator?->name;

        $this->backups->forget($backup);

        ActivityLogger::record(ActivityLog::ACTION_BACKUP_DELETED, null, [
            'user' => $request->user(),
            'context' => ['filename' => $filename, 'created_by' => $creator],
        ]);

        return redirect()
            ->route('backups.index')
            ->with('success', "Backup {$filename} was deleted.");
    }

    /**
     * Show the restore confirmation screen.
     */
    public function confirmRestore(Request $request, Backup $backup): View
    {
        $this->authorize('restore', $backup);

        return view('backups.restore', [
            'backup' => $backup->load('creator'),
            'safetyBackup' => config('backup.restore.safety_backup') === true,
            'maintenanceMode' => config('backup.restore.maintenance_mode') === true,
        ]);
    }

    /**
     * Queue the restore of a backup.
     */
    public function restore(Request $request, Backup $backup): RedirectResponse
    {
        $this->authorize('restore', $backup);

        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
        ]);

        if ($validated['confirmation'] !== $backup->filename) {
            return back()->withErrors(['confirmation' => 'Type the backup filename exactly to confirm the restore.']);
        }

        RunRestore::dispatch($backup, $request->user());

        return redirect()
            ->route('backups.show', $backup)
            ->with('success', 'The restore has been queued. A safety backup of the current database is taken first.');
    }

    /**
     * Turn restoring on or off for the whole installation.
     */
    public function updateRestoreAccess(Request $request): RedirectResponse
    {
        $this->authorize('manageRestoreAccess', Backup::class);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        // A form posts the string "0" or "1" while a test posts a real bool or
        // int, so the value is cast rather than compared.
        $enabled = filter_var($validated['enabled'], FILTER_VALIDATE_BOOLEAN);

        $this->access->set($enabled, $request->user());

        ActivityLogger::record(ActivityLog::ACTION_RESTORE_ACCESS_CHANGED, null, [
            'user' => $request->user(),
            'context' => ['restore_enabled' => $enabled],
        ]);

        return redirect()
            ->route('backups.index')
            ->with('success', $enabled
                ? 'Restoring is now switched on. Anyone with the restore permission can put a backup back.'
                : 'Restoring is now switched off. Nobody can put a backup back until it is switched on again.');
    }
}
