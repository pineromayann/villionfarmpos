@extends('layouts.app')

@section('title', 'Refunds')
@section('heading', 'Refunds')
@section('subheading', 'Process and review product returns from sales.')

@section('actions')
    <div x-data="refundApp()">
        <button @click="open = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="refunds" class="h-4 w-4" />
            New refund
        </button>

        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <div @click.outside="open = false" class="w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Process a refund</h2>
                    <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                        <x-icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                <form method="POST" action="{{ route('refunds.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="sale_id">Sale</label>
                        <select
                            name="sale_id"
                            id="sale_id"
                            x-model="saleId"
                            @change="loadItems()"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                        >
                            <option value="">Select a sale...</option>
                            @foreach ($sales as $sale)
                                <option value="{{ $sale->id }}">
                                    #{{ $sale->id }} — {{ $sale->customer?->name ?? 'Walk-in' }} ({{ $sale->created_at->format('n/j/Y') }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <template x-if="loading">
                        <p class="text-sm text-gray-500">Loading items...</p>
                    </template>

                    <div x-show="saleId && !loading && items.length" class="max-h-72 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3">
                        <template x-for="(item, index) in items" :key="item.id">
                            <div class="flex items-center gap-3 text-sm">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900" x-text="item.product"></p>
                                    <p class="text-xs text-gray-500">
                                        <span x-text="'Returnable: ' + item.remaining + (item.unit ? ' ' + item.unit : '')"></span>
                                        &middot; ₱<span x-text="Number(item.unit_price).toFixed(2)"></span>/unit
                                    </p>
                                </div>
                                <input
                                    type="hidden"
                                    :name="`items[${index}][sale_item_id]`"
                                    :value="item.id"
                                    :disabled="!Number(item.refund_qty)"
                                >
                                <input
                                    type="number"
                                    min="0"
                                    :max="item.remaining"
                                    step="0.01"
                                    x-model.number="item.refund_qty"
                                    :disabled="item.remaining <= 0"
                                    placeholder="Qty"
                                    class="w-24 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                                >
                                <span class="w-24 text-right font-semibold text-gray-900">
                                    ₱<span x-text="(item.refund_qty * item.unit_price).toFixed(2)"></span>
                                </span>
                            </div>
                        </template>
                    </div>

                    <div>
                        <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="note">Note</label>
                        <input type="text" name="note" id="note" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    </div>

                    <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                        Process refund
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" x-data>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Total refunded</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">₱{{ number_format($totalRefunded, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Refunds</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $refunds->count() }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Units returned</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($refunds->sum('quantity'), 2) }}</p>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Date</th>
                    <th class="px-5 py-3">Product</th>
                    <th class="px-5 py-3">Sale</th>
                    <th class="px-5 py-3">Quantity</th>
                    <th class="px-5 py-3 text-right">Refunded</th>
                    <th class="px-5 py-3">Note</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($refunds as $refund)
                    <tr>
                        <td class="px-5 py-3 text-gray-700">{{ $refund->created_at->format('n/j/Y, g:i A') }}</td>
                        <td class="px-5 py-3 font-medium text-gray-900">{{ $refund->product->name ?? 'Unknown product' }}</td>
                        <td class="px-5 py-3 text-gray-700">#{{ $refund->sale_id }}</td>
                        <td class="px-5 py-3 text-gray-700">
                            {{ rtrim(rtrim(number_format((float) $refund->quantity, 2), '0'), '.') }} {{ $refund->product->unit }}
                        </td>
                        <td class="px-5 py-3 text-right font-semibold text-gray-900">₱{{ number_format((float) $refund->line_total, 2) }}</td>
                        <td class="px-5 py-3 text-gray-600">{{ $refund->note ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-gray-500">No refunds recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        function refundApp() {
            return {
                open: false,
                saleId: '',
                items: [],
                loading: false,
                async loadItems() {
                    if (!this.saleId) {
                        this.items = [];
                        return;
                    }
                    this.loading = true;
                    const response = await fetch(`/refunds/sales/${this.saleId}/items`);
                    this.items = (await response.json()).map((item) => ({ ...item, refund_qty: 0 }));
                    this.loading = false;
                },
            };
        }
    </script>
@endsection