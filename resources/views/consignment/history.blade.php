@extends('layouts.app')

@section('title', 'Consignment History')
@section('heading', 'Consignment History')
@section('subheading', 'Every consignment event — receipts, sales, adjustments, and payments — in one ledger.')

@section('content')
    <form method="GET" action="{{ route('consignment.history') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4">
        <div>
            <label class="text-xs font-medium uppercase tracking-wide text-gray-500">Partner</label>
            <select name="partner_id" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                <option value="">All partners</option>
                @foreach ($partners as $partner)
                    <option value="{{ $partner->id }}" @selected(($filters['partner_id'] ?? null) == $partner->id)>{{ $partner->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs font-medium uppercase tracking-wide text-gray-500">From</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
        </div>
        <div>
            <label class="text-xs font-medium uppercase tracking-wide text-gray-500">To</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
        </div>
        <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">Filter</button>
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Date</th>
                    <th class="px-5 py-3">Type</th>
                    <th class="px-5 py-3">Partner</th>
                    <th class="px-5 py-3">Description</th>
                    <th class="px-5 py-3 text-right">Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($ledger as $entry)
                    <tr>
                        <td class="px-5 py-3 text-gray-500">{{ $entry['date']->format('n/j/Y') }}</td>
                        <td class="px-5 py-3">
                            @if ($entry['type'] === 'received')
                                <span class="inline-block rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">Received</span>
                            @elseif ($entry['type'] === 'sold')
                                <span class="inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Sold</span>
                            @elseif ($entry['type'] === 'adjusted')
                                <span class="inline-block rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">Adjusted</span>
                            @else
                                <span class="inline-block rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-medium text-violet-700">Payment</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 font-medium text-gray-900">{{ $entry['partner'] }}</td>
                        <td class="px-5 py-3 text-gray-700">
                            {{ $entry['label'] }}
                            @if ($entry['detail'])
                                <span class="text-xs text-gray-400">&middot; {{ $entry['detail'] }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right font-semibold text-gray-900">₱{{ number_format($entry['value'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-8 text-center text-gray-500">No consignment activity in this range.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection