@extends('layouts.app')

@section('title', 'Restore backup')
@section('heading', 'Restore from backup')
@section('subheading', 'This replaces the live database with the contents of '.$backup->filename.'.')

@section('actions')
    <a href="{{ route('backups.show', $backup) }}" class="rounded-lg border border-gray-200 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
        Cancel
    </a>
@endsection

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="rounded-lg border-2 border-red-300 bg-red-50 p-5">
            <div class="flex items-start gap-3">
                <x-icon name="warning" class="h-6 w-6 shrink-0 text-red-700" />
                <div class="text-sm text-red-900">
                    <h2 class="text-base font-semibold">This will overwrite the live POS and inventory data</h2>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Every sale, sale item and refund currently stored will be replaced.</li>
                        <li>Product stock levels and the stock movement history will be replaced.</li>
                        <li>Purchase orders, received deliveries and supplier records will be replaced.</li>
                        <li>Consignment stock, consignment sales and partner balances will be replaced.</li>
                        <li>Anything recorded since {{ $backup->created_at?->format('d M Y H:i') }} will be lost.</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-gray-900">What happens when you continue</h2>
            <ol class="mt-3 space-y-2 text-sm text-gray-700">
                @if ($maintenanceMode)
                    <li class="flex gap-2"><span class="font-medium text-gray-900">1.</span> The site is put into maintenance mode, so no sale, receipt or stock movement can be written during the restore.</li>
                @endif
                @if ($safetyBackup)
                    <li class="flex gap-2"><span class="font-medium text-gray-900">{{ $maintenanceMode ? '2.' : '1.' }}</span> A safety backup of the <em>current</em> database is taken and stored first.</li>
                @endif
                <li class="flex gap-2"><span class="font-medium text-gray-900">{{ ($maintenanceMode ? 2 : 0) + ($safetyBackup ? 1 : 0) + 1 }}.</span> The backup file is verified, then loaded into the database.</li>
                <li class="flex gap-2"><span class="font-medium text-gray-900">{{ ($maintenanceMode ? 2 : 0) + ($safetyBackup ? 1 : 0) + 2 }}.</span> {{ $backup->type === App\Models\Backup::TYPE_FULL ? 'The archived business files are written back into the project.' : 'Only the database is restored.' }}</li>
                <li class="flex gap-2"><span class="font-medium text-gray-900">{{ ($maintenanceMode ? 2 : 0) + ($safetyBackup ? 1 : 0) + 3 }}.</span> Everyone is signed out and must log in again.</li>
            </ol>

            @if ($maintenanceMode)
                <p class="mt-3 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900">
                    The site will show a "be right back" page for a short while.
                    <strong>You do not need to do anything</strong>, and you do not need to close this tab.
                    The site puts itself back on its own, so simply refresh this page a minute or two later to
                    see the result.
                </p>
            @endif

            @unless ($safetyBackup)
                <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    The safety backup is disabled in this environment. There will be no way back from this restore.
                </p>
            @endunless
        </div>

        <form method="POST" action="{{ route('backups.restore', $backup) }}" class="rounded-lg border border-gray-200 bg-white p-5">
            @csrf

            <label class="block text-xs font-medium uppercase tracking-wide text-gray-500" for="confirmation">
                Type the backup filename to confirm
            </label>
            <p class="mt-1 font-mono text-xs text-gray-500">{{ $backup->filename }}</p>

            <input
                type="text"
                name="confirmation"
                id="confirmation"
                autocomplete="off"
                required
                placeholder="{{ $backup->filename }}"
                class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm focus:border-gray-400 focus:outline-none"
            >

            <label class="mt-4 flex items-start gap-2 text-sm text-gray-700">
                <input type="checkbox" required class="mt-0.5 rounded border-gray-300">
                <span>Sales are closed off, the till is counted, and I understand this overwrites the current data.</span>
            </label>

            <div class="mt-5 flex justify-end gap-2">
                <a href="{{ route('backups.show', $backup) }}" class="rounded-lg border border-gray-200 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">
                    Restore the database now
                </button>
            </div>
        </form>
    </div>
@endsection
