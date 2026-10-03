@extends('layouts.app')

@section('title', 'Backups')
@section('heading', 'Backups')
@section('subheading', 'Restorable snapshots of the database and the business critical files.')

@section('actions')
    @can('create', App\Models\Backup::class)
        <div x-data="{ open: false, type: 'database', frequency: 'none' }" class="flex items-center gap-3">
            <button @click="open = true" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                <x-icon name="backup" class="h-4 w-4" />
                Create backup
            </button>

            <template x-teleport="body">
                <x-modal title="Create backup" state="open">
                    <form id="backup-create-form" method="POST" action="{{ route('backups.store') }}" class="space-y-4">
                        @csrf

                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="backup_type">What to capture</label>
                            <select name="type" id="backup_type" x-model="type" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="database">Database only</option>
                                <option value="full">Database and business files</option>
                            </select>
                        </div>

                        <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600" x-show="type === 'full'">
                            Includes {{ implode(', ', (array) config('backup.files')) }}.
                        </p>

                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="backup_frequency">Retention group</label>
                            <select name="frequency" id="backup_frequency" x-model="frequency" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="none">Keep forever</option>
                                <option value="daily">Daily &mdash; keep {{ config('backup.retention.daily') }}</option>
                                <option value="weekly">Weekly &mdash; keep {{ config('backup.retention.weekly') }}</option>
                                <option value="monthly">Monthly &mdash; keep {{ config('backup.retention.monthly') }}</option>
                            </select>
                        </div>

                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            The backup runs in the background. Sales, receipts and stock movements can carry on while it works.
                        </p>
                    </form>

                    <x-slot:footer>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button type="button" @click="open = false" class="rounded-lg border border-gray-200 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="submit" form="backup-create-form" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                                Queue backup
                            </button>
                        </div>
                    </x-slot:footer>
                </x-modal>
            </template>
        </div>
    @endcan
@endsection

@section('content')
    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach (['completed' => 'Completed', 'running' => 'Running', 'pending' => 'Pending', 'failed' => 'Failed'] as $key => $label)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $summary[$key] ?? 0 }}</p>
            </div>
        @endforeach
    </div>

    @if ($queueStalled)
        <div class="mb-6 rounded-lg border-2 border-red-300 bg-red-50 p-4 text-sm text-red-900">
            <p class="flex items-start gap-2 font-semibold">
                <x-icon name="warning" class="mt-0.5 h-5 w-5 shrink-0 text-red-700" />
                Backups are not running
            </p>
            <p class="mt-2 pl-7">
                A backup has been waiting for over {{ (int) config('backup.queue.stalled_after_minutes', 5) }} minutes
                without starting. Nothing is picking up the work, so backups and restores will not happen until
                that is fixed.
            </p>
            <p class="mt-2 pl-7">
                This is a hosting setting rather than something you can change here. Ask whoever manages the
                server to set up an automatic task that runs
                <span class="font-mono text-xs">php artisan queue:work --stop-when-empty</span>
                every minute, and
                <span class="font-mono text-xs">php artisan schedule:run</span> every minute so the daily backups
                happen on their own.
            </p>
        </div>
    @endif

    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600">
        @if ($diskIsPrivate)
            <p>
                Backups are saved on the <span class="font-medium text-gray-900">{{ $disk }}</span> disk, outside the folders the website can serve, so the files cannot be reached by guessing a web address.
                Only a signed-in administrator can download one.
                Retention keeps the last <span class="font-medium text-gray-900">{{ $retention['daily'] ?? 0 }}</span> daily,
                <span class="font-medium text-gray-900">{{ $retention['weekly'] ?? 0 }}</span> weekly and
                <span class="font-medium text-gray-900">{{ $retention['monthly'] ?? 0 }}</span> monthly backups.
            </p>
        @else
            <p class="font-medium text-red-700">
                New backups are switched off. The <span class="font-medium">{{ $disk }}</span> disk is somewhere the
                website could serve, and a backup holds every record in the system, so nothing is written there until
                it is moved somewhere private. Please contact whoever looks after this site.
            </p>
        @endif
    </div>

    @can('manageRestoreAccess', App\Models\Backup::class)
        <div class="mb-6 rounded-lg border-2 p-4 text-sm {{ $restoreEnabled ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-gray-200 bg-white text-gray-700' }}">
            <p class="font-semibold">
                {{ $restoreEnabled ? 'Restoring is switched on' : 'Restoring is switched off' }}
            </p>

            @if ($restoreEnabled)
                <p class="mt-1">
                    Anyone who can manage backups can put a backup back. A copy of your current data is always taken
                    first, so a mistake can be undone. You can leave this on, or switch it off between restores so
                    nobody can do it by accident.
                </p>
            @else
                <p class="mt-1">
                    Putting a backup back is turned off, so nobody can do it by accident. Switch it on when you need
                    to recover something.
                </p>
            @endif

            <form method="POST" action="{{ route('backups.restore.access') }}" class="mt-3">
                @csrf
                <input type="hidden" name="enabled" value="{{ $restoreEnabled ? 0 : 1 }}">

                <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-white {{ $restoreEnabled ? 'bg-amber-700 hover:bg-amber-800' : 'bg-gray-800 hover:bg-gray-900' }}">
                    {{ $restoreEnabled ? 'Switch restoring off' : 'Switch restoring on' }}
                </button>
            </form>
        </div>
    @endcan

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        @if ($backups->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-gray-500">
                No backups yet. Use <span class="font-medium">Create backup</span> or run
                <span class="font-mono text-xs">php artisan backup:create</span>.
            </p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Backup</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Size</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Created by</th>
                        <th class="px-4 py-3 font-medium">Created at</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($backups as $backup)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('backups.show', $backup) }}" class="font-mono text-xs text-emerald-700 hover:underline">
                                    {{ $backup->filename }}
                                </a>
                                @if ($backup->frequency)
                                    <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-600">{{ $backup->frequency }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $backup->typeLabel() }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $backup->humanSize() }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $statusClass = match ($backup->status) {
                                        'completed' => 'bg-emerald-100 text-emerald-700',
                                        'running' => 'bg-blue-100 text-blue-700',
                                        'failed' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-600',
                                    };
                                @endphp
                                <span class="rounded px-2 py-0.5 text-xs font-medium {{ $statusClass }}">{{ ucfirst($backup->status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $backup->creator?->name ?? 'System' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $backup->created_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('backups.show', $backup) }}" class="rounded border border-gray-200 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">View</a>

                                    @can('download', $backup)
                                        <a href="{{ route('backups.download', $backup) }}" class="inline-flex items-center gap-1 rounded border border-gray-200 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                            <x-icon name="download" class="h-3.5 w-3.5" />
                                            Download
                                        </a>
                                    @endcan

                                    @can('restore', $backup)
                                        <a href="{{ route('backups.restore.confirm', $backup) }}" class="rounded border border-red-200 px-2 py-1 text-xs text-red-700 hover:bg-red-50">Restore</a>
                                    @endcan

                                    @can('delete', $backup)
                                        <form method="POST" action="{{ route('backups.destroy', $backup) }}"
                                            onsubmit="return confirm('Delete {{ $backup->filename }}? This cannot be undone.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded border border-red-200 px-2 py-1 text-xs text-red-700 hover:bg-red-50">Delete</button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4">
        {{ $backups->links() }}
    </div>
@endsection
