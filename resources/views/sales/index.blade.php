@extends('layouts.app')

@section('title', 'Sales')
@section('heading', 'Sales')
@section('subheading', 'History of every completed transaction.')

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Total revenue</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">₱{{ number_format($totalRevenue, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Items sold</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $itemsSold }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Avg. sale</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">₱{{ number_format($avgSale, 2) }}</p>
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
                            <tr x-data="{ open: false }" @click="open = true" class="cursor-pointer transition-colors hover:bg-gray-50">
                                <td class="py-3 text-gray-700">{{ $sale->created_at->format('n/j/Y, g:i:s A') }}</td>
                                <td class="py-3 text-gray-900">{{ $sale->customer?->name ?? 'Walk-in' }}</td>
                                <td class="py-3 text-gray-700">{{ $sale->items->sum('quantity') }}</td>
                                <td class="py-3">
                                    <span class="inline-block rounded-full border border-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                        {{ str($sale->payment_method)->replace('_', ' ')->title() }}
                                    </span>
                                </td>
                                <td class="py-3 text-right font-semibold text-gray-900">₱{{ number_format($sale->total, 2) }}</td>

                                <template x-teleport="body">
<div x-show="open" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-black/30 p-4">
    <div @click.outside="open = false" class="mx-auto w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl">
                                            <div class="mb-4 flex items-center justify-between">
                                                <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-900">
                                                    <x-icon name="receipt" class="h-5 w-5 text-gray-400" />
                                                    Sale #{{ $sale->id }}
                                                </h2>
                                                <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                                                    <x-icon name="x" class="h-5 w-5" />
                                                </button>
                                            </div>

                                            <div class="grid grid-cols-1 gap-4 rounded-lg bg-gray-50 p-4 sm:grid-cols-3">
                                                <div>
                                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Date</p>
                                                    <p class="mt-0.5 text-sm text-gray-900">{{ $sale->created_at->format('n/j/Y, g:i:s A') }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Customer</p>
                                                    <p class="mt-0.5 text-sm text-gray-900">{{ $sale->customer?->name ?? 'Walk-in customer' }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Payment</p>
                                                    <p class="mt-0.5">
                                                        <span class="inline-block rounded-full border border-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                                            {{ str($sale->payment_method)->replace('_', ' ')->title() }}
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>

                                            <div class="mt-4 overflow-x-auto">
                                                <table class="w-full text-left text-sm">
                                                    <thead>
                                                        <tr class="text-xs uppercase tracking-wide text-gray-500">
                                                            <th class="pb-2 font-medium">Product</th>
                                                            <th class="pb-2 text-center font-medium">Qty</th>
                                                            <th class="pb-2 text-right font-medium">Unit price</th>
                                                            <th class="pb-2 text-right font-medium">Line total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100">
                                                        @forelse ($sale->items as $item)
                                                            <tr>
                                                                <td class="py-2.5 font-medium text-gray-900">{{ $item->product?->name ?? 'Unknown product' }}</td>
                                                                <td class="py-2.5 text-center text-gray-700">
                                                                    {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->product?->unit }}
                                                                </td>
                                                                <td class="py-2.5 text-right text-gray-700">₱{{ number_format($item->unit_price, 2) }}</td>
                                                                <td class="py-2.5 text-right font-semibold text-gray-900">₱{{ number_format($item->line_total, 2) }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr>
                                                                <td colspan="4" class="py-4 text-center text-gray-500">No items on this sale.</td>
                                                            </tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="mt-4 space-y-1.5 border-t border-gray-100 pt-3 text-sm">
                                                <div class="flex justify-between text-gray-600">
                                                    <span>Subtotal</span>
                                                    <span>₱{{ number_format($sale->subtotal, 2) }}</span>
                                                </div>
                                                <div class="flex justify-between text-gray-600">
                                                    <span>Discount</span>
                                                    <span>-₱{{ number_format($sale->discount, 2) }}</span>
                                                </div>
                                                <div class="flex justify-between font-semibold text-gray-900">
                                                    <span>Total</span>
                                                    <span>₱{{ number_format($sale->total, 2) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
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
                        <span class="font-semibold text-gray-900">₱{{ number_format($product->revenue, 2) }}</span>
                    </li>
                @empty
                    <li class="text-sm text-gray-500">No sales recorded yet.</li>
                @endforelse
            </ul>

            <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-500">Catalog: {{ $catalogCount }} products</p>
        </div>
    </div>
@endsection
