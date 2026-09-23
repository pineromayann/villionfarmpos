@extends('layouts.app')

@section('title', 'Stock')
@section('heading', 'Stock movements')
@section('subheading', 'Every stock in, out, sale and return recorded against products.')

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Stock in</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700">{{ number_format($totalIn, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-red-600">Stock out</p>
            <p class="mt-1 text-2xl font-bold text-red-700">{{ number_format($totalOut, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Net change</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($netChange, 2) }}</p>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5">
        <form method="GET" action="{{ route('stock.movements.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="product_id">Product</label>
                <select name="product_id" id="product_id" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    <option value="">All products</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected($filters['product_id'] ?? null == $product->id)>{{ $product->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="type">Type</label>
                <select name="type" id="type" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="in" @selected(($filters['type'] ?? null) === 'in')>In</option>
                    <option value="out" @selected(($filters['type'] ?? null) === 'out')>Out</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="date_from">From</label>
                <input type="date" name="date_from" id="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="date_to">To</label>
                <input type="date" name="date_to" id="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
            </div>
            <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">Filter</button>
            <a href="{{ route('stock.movements.index') }}" class="rounded-lg border border-gray-200 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">Reset</a>
        </form>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Date</th>
                    <th class="px-5 py-3">Product</th>
                    <th class="px-5 py-3">Type</th>
                    <th class="px-5 py-3">Quantity</th>
                    <th class="px-5 py-3">Reason</th>
                    <th class="px-5 py-3">Source</th>
                    <th class="px-5 py-3">By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($movements as $movement)
                    <tr>
                        <td class="px-5 py-3 text-gray-700">{{ $movement->created_at->format('n/j/Y, g:i A') }}</td>
                        <td class="px-5 py-3 font-medium text-gray-900">{{ $movement->product->name ?? 'Unknown product' }}</td>
                        <td class="px-5 py-3">
                            @if ($movement->isIn())
                                <span class="inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">In</span>
                            @else
                                <span class="inline-block rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700">Out</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 {{ $movement->isIn() ? 'text-emerald-700' : 'text-red-700' }}">
                            {{ $movement->isIn() ? '+' : '-' }}{{ number_format((float) $movement->quantity, 2) }} {{ $movement->unit?->abbreviation ?? $movement->product?->unit }}
                        </td>
                        <td class="px-5 py-3 text-gray-700">{{ ucfirst($movement->reason ?? '—') }}</td>
                        <td class="px-5 py-3 text-gray-700">
                            @if ($movement->supplier)
                                {{ $movement->supplier->name }}
                            @elseif ($movement->ref_type === 'sale')
                                <a href="{{ route('sales.index') }}" class="text-sky-600 hover:underline">Sale #{{ $movement->ref_id }}</a>
                            @else
                                @if ($movement->unit_cost !== null)
                                    ₱{{ number_format($movement->unit_cost, 2) }}/unit
                                @else
                                    —
                                @endif
                            @endif
                        </td>
                        <td class="px-5 py-3 text-gray-600">{{ $movement->user?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-gray-500">No stock movements recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection