@extends('layouts.app')

@section('title', 'Sales')
@section('heading', 'Sales')
@section('subheading', 'History of every completed transaction.')

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Total revenue</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">${{ number_format($totalRevenue, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Items sold</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $itemsSold }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Avg. sale</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">${{ number_format($avgSale, 2) }}</p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 lg:col-span-2">
            <h2 class="font-semibold text-gray-900">Transactions</h2>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wide text-gray-500">
                            <th class="pb-2 font-medium">Date</th>
                            <th class="pb-2 font-medium">Customer</th>
                            <th class="pb-2 font-medium">Items</th>
                            <th class="pb-2 font-medium">Payment</th>
                            <th class="pb-2 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($transactions as $sale)
                            <tr>
                                <td class="py-3 text-gray-700">{{ $sale->created_at->format('n/j/Y, g:i:s A') }}</td>
                                <td class="py-3 text-gray-900">{{ $sale->customer?->name ?? 'Walk-in' }}</td>
                                <td class="py-3 text-gray-700">{{ $sale->itemCount() }}</td>
                                <td class="py-3">
                                    <span class="inline-block rounded-full border border-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                        {{ str($sale->payment_method)->replace('_', ' ')->title() }}
                                    </span>
                                </td>
                                <td class="py-3 text-right font-semibold text-gray-900">${{ number_format($sale->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-gray-500">No sales recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="font-semibold text-gray-900">Top products</h2>

            <ul class="mt-4 space-y-3">
                @forelse ($topProducts as $product)
                    <li class="flex items-start justify-between text-sm">
                        <div>
                            <p class="font-medium text-gray-900">{{ $product->name }}</p>
                            <p class="text-gray-500">{{ (int) $product->units_sold }} units sold</p>
                        </div>
                        <span class="font-semibold text-gray-900">${{ number_format($product->revenue, 2) }}</span>
                    </li>
                @empty
                    <li class="text-sm text-gray-500">No sales recorded yet.</li>
                @endforelse
            </ul>

            <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-500">Catalog: {{ $catalogCount }} products</p>
        </div>
    </div>
@endsection
