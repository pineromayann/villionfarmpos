@extends('layouts.app')

@section('title', 'Consignment Sales')
@section('heading', 'Consignment Sales')
@section('subheading', 'Sales of consigned goods recorded through the point of sale.')

@section('content')
    <form method="GET" action="{{ route('consignment.sales') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4">
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

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Units sold</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($unitsSold, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Retail value</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">₱{{ number_format($totalRetail, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Payable to partners</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700">₱{{ number_format($totalPayable, 2) }}</p>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Sold at</th>
                    <th class="px-5 py-3">Partner</th>
                    <th class="px-5 py-3">Product</th>
                    <th class="px-5 py-3 text-right">Qty</th>
                    <th class="px-5 py-3 text-right">Unit price</th>
                    <th class="px-5 py-3 text-right">Line total</th>
                    <th class="px-5 py-3 text-right">Payable</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $sale)
                    <tr>
                        <td class="px-5 py-3 text-gray-500">{{ $sale->sold_at->format('n/j/Y g:i A') }}</td>
                        <td class="px-5 py-3 font-medium text-gray-900">{{ $sale->partner?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-gray-700">
                            {{ $sale->product->name }}
                            @if ($sale->sale)
                                <span class="text-xs text-gray-400">&middot; Sale #{{ $sale->sale->id }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-gray-900">{{ rtrim(rtrim(number_format((float) $sale->quantity, 2), '0'), '.') }} {{ $sale->unit?->abbreviation ?? $sale->product->unit }}</td>
                        <td class="px-5 py-3 text-right text-gray-700">₱{{ number_format((float) $sale->unit_price, 2) }}</td>
                        <td class="px-5 py-3 text-right font-semibold text-gray-900">₱{{ number_format((float) $sale->line_total, 2) }}</td>
                        <td class="px-5 py-3 text-right text-emerald-700">₱{{ number_format((float) $sale->payable_amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-gray-500">No consignment sales in this range.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection