@extends('layouts.app')

@section('title', 'Backup')
@section('heading', $backup->filename)
@section('subheading', $backup->typeLabel().' backup taken '.$backup->created_at?->format('d M Y \a\t H:i').'.')

@section('actions')
    <div class="flex items-center gap-2">
        <a href="{{ route('backups.index') }}" class="rounded-lg border border-gray-200 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
            Back to backups
        </a>

        @can('download', $backup)
            <a href="{{ route('backups.download', $backup) }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                <x-icon name="download" class="h-4 w-4" />
                Download
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <dl class="grid gap-4 sm:grid-cols-2">
                    @php
                        $details = [
                            'Type' => $backup->typeLabel(),
                            'Status' => ucfirst($backup->status),
                            'Size' => $backup->humanSize(),
                            'Disk' => $backup->disk,
                            'Database' => $backup->database_name ?? '—',
                            'Retention group' => $backup->frequency ?? 'Kept forever',
                            'Created by' => $backup->creator?->name ?? 'System',
                            'Created at' => $backup->created_at?->format('d M Y H:i:s') ?? '—',
                            'Started at' => $backup->started_at?->format('d M Y H:i:s') ?? '—',
                            'Completed at' => $backup->completed_at?->format('d M Y H:i:s') ?? '—',
                            'Duration' => $backup->durationInSeconds() === null ? '—' : $backup->durationInSeconds().'s',
                            'Checksum' => $backup->checksum ?? '—',
                        ];
                    @endphp

                    @foreach ($details as $label => $value)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</dt>
                            <dd class="mt-0.5 break-words text-sm text-gray-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            @if ($backup->error_message)
                <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                    <h2 class="text-sm font-semibold text-red-800">The backup failed</h2>
                    <p class="mt-1 font-mono text-xs text-red-700">{{ $backup->error_message }}</p>
                </div>
            @endif

            @if ($backup->isCompleted() && ! $backup->fileExists())
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    The record says this backup completed, but the file is no longer on the
                    <span class="font-medium">{{ $backup->disk }}</span> disk. It cannot be downloaded or restored.
                </div>
            @endif

            @if (! empty($backup->snapshot))
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold text-gray-900">Database captured</h2>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm text-gray-700 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Method</dt>
                            <dd class="font-mono text-xs">{{ $backup->snapshot['driver'] ?? 'unknown' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Tables</dt>
                            <dd class="text-xs">{{ $backup->snapshot['tables'] ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Rows</dt>
                            <dd class="text-xs">
                                @if (($backup->snapshot['rows'] ?? null) === null)
                                    <span class="text-gray-500">not reported</span>
                                @else
                                    {{ number_format($backup->snapshot['rows']) }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            @endif

            @if (! empty($backup->included_paths))
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold text-gray-900">Files included</h2>
                    <ul class="mt-2 space-y-1">
                        @foreach ($backup->included_paths as $path)
                            <li class="font-mono text-xs text-gray-600">{{ $path }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-gray-900">Activity</h2>

                @if ($activity->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">Nothing recorded yet.</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($activity as $entry)
                            <li class="flex items-start justify-between gap-4 py-2">
                                <div>
                                    <span class="font-medium text-gray-900">{{ str_replace('.', ' ', $entry->action) }}</span>
                                    @if ($entry->result !== 'success')
                                        <span class="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-700">failed</span>
                                    @endif
                                    <p class="text-xs text-gray-500">
                                        {{ $entry->user?->name ?? 'System' }}
                                        @if ($entry->ip_address) &middot; {{ $entry->ip_address }} @endif
                                    </p>
                                </div>
                                <span class="shrink-0 text-xs text-gray-500">{{ $entry->created_at?->format('d M H:i') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-gray-900">Restore</h2>

                @can('restore', $backup)
                    <p class="mt-2 text-sm text-gray-600">
                        Putting this backup back overwrites current sales, stock levels,
                        purchase records and consignment balances. A copy of your present data is taken first, so
                        you can go back if this was a mistake.
                    </p>

                    <a href="{{ route('backups.restore.confirm', $backup) }}" class="mt-3 inline-block rounded-lg border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                        Restore from this backup
                    </a>
                @else
                    <p class="mt-2 text-sm text-gray-500">
                        @if (! auth()->user()->can('backup.restore'))
                            You do not have permission to restore backups.
                        @elseif ($backup->status === \App\Models\Backup::STATUS_FAILED)
                            This backup did not finish, so there is nothing to restore from. Take a new backup
                            and try again.
                        @elseif (! $backup->isCompleted())
                            This backup is still being made, so there is nothing to restore from yet. Come back
                            once it says <span class="font-medium text-gray-700">Completed</span>.
                        @else
                            Restoring is switched off. Switch it on from the
                            <a href="{{ route('backups.index') }}" class="underline">Backups</a> screen when you need it.
                        @endif
                    </p>
                @endcan
            </div>
        </div>
    </div>
@endsection
