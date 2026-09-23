@extends('layouts.app')

@section('title', 'Receive Consignment')
@section('heading', 'Receive Consignment')
@section('subheading', 'Log goods delivered by consignment partners at the agreed consignment price.')

@php
    $productRows = $products->map(fn ($product) => [
        'id' => $product->id,
        'name' => $product->name,
        'baseUnitId' => $product->baseUnit?->id ?? null,
        'units' => $product->sellingUnits->map(fn ($pu) => [
            'id' => $pu->unit_id,
            'name' => $pu->unit->name,
            'abbreviation' => $pu->unit->abbreviation,
            'base' => (bool) $pu->is_base,
        ])->values()->all(),
    ])->values()->all();

    $partnerRows = $partners->map(fn ($partner) => [
        'id' => $partner->id,
        'name' => $partner->name,
    ])->values()->all();
@endphp

@section('actions')
    <div x-data="{ receive: false }">
        <button @click="receive = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="plus" class="h-4 w-4" />
            Receive consignment
        </button>

        <template x-teleport="body">
            <div x-show="receive" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                <div @click.outside="receive = false" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">New consignment</h2>
                        <button @click="receive = false" class="text-gray-400 hover:text-gray-600">
                            <x-icon name="x" class="h-5 w-5" />
                        </button>
                    </div>

                    <form method="POST" action="{{ route('consignment.receive.store') }}" class="space-y-4" x-data="consignmentForm()">
                        @csrf

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="partner_id">Partner</label>
                                <select name="partner_id" x-model="form.partner_id" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                    <option value="">Select partner...</option>
                                    @foreach ($partners as $partner)
                                        <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="received_at">Received date</label>
                                <input type="date" name="received_at" x-model="form.received_at" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="note">Note</label>
                            <input type="text" name="note" x-model="form.note" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>

                        <div class="overflow-x-auto rounded-lg border border-gray-200">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                        <th class="px-4 py-2">Product</th>
                                        <th class="px-4 py-2">Qty</th>
                                        <th class="px-4 py-2">Unit</th>
                                        <th class="px-4 py-2 text-right">Consignment price</th>
                                        <th class="px-4 py-2 text-right">Total</th>
                                        <th class="px-4 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(row, index) in rows" :key="index">
                                        <tr>
                                            <td class="px-4 py-2">
                                                <select x-model="row.product_id" @change="pickProduct(row)" :name="'items[' + index + '][product_id]'" class="w-40 rounded border border-gray-200 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                                                    <option value="">Select...</option>
                                                    <template x-for="product in products" :key="product.id">
                                                        <option :value="product.id" x-text="product.name"></option>
                                                    </template>
                                                </select>
                                            </td>
                                            <td class="px-4 py-2">
                                                <input type="number" step="0.01" min="0.01" x-model="row.quantity" :name="'items[' + index + '][quantity]'" class="w-24 rounded border border-gray-200 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                                            </td>
                                            <td class="px-4 py-2">
                                                <select x-model="row.unit_id" :name="'items[' + index + '][unit_id]'" class="rounded border border-gray-200 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                                                    <template x-for="unit in rowUnits(row)" :key="unit.id">
                                                        <option :value="unit.id" x-text="unit.abbreviation"></option>
                                                    </template>
                                                </select>
                                            </td>
                                            <td class="px-4 py-2">
                                                <input type="number" step="0.01" min="0" x-model="row.unit_cost" :name="'items[' + index + '][unit_cost]'" class="w-28 rounded border border-gray-200 px-2 py-1.5 text-right text-sm focus:border-gray-400 focus:outline-none">
                                            </td>
                                            <td class="px-4 py-2 text-right font-semibold text-gray-900" x-text="'₱' + lineTotal(row)"></td>
                                            <td class="px-4 py-2 text-right">
                                                <button type="button" @click="rows.splice(index, 1)" class="text-gray-400 hover:text-red-600">
                                                    <x-icon name="trash" class="h-4 w-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-gray-200 bg-gray-50">
                                        <td colspan="4" class="px-4 py-2 text-right text-sm font-medium text-gray-700">Total consignment value</td>
                                        <td class="px-4 py-2 text-right font-bold text-gray-900" x-text="'₱' + formTotal()"></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <button type="button" @click="addRow()" class="inline-flex items-center gap-1.5 text-sm font-medium text-sky-600 hover:text-sky-800">
                            <x-icon name="plus" class="h-4 w-4" />
                            Add line
                        </button>

                        <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                            Record consignment
                        </button>
                    </form>
                </div>
            </div>
        </template>
    </div>
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-sky-600">Partners</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $partnersCount }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Received this month</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700">₱{{ number_format($receivedThisMonth, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">On-hand value</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">₱{{ number_format($onHandValue, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Balance due</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">₱{{ number_format($balanceDue, 2) }}</p>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Consignment</th>
                    <th class="px-5 py-3">Partner</th>
                    <th class="px-5 py-3">Items</th>
                    <th class="px-5 py-3 text-right">Value</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($consignments as $consignment)
                    <tr x-data="{ details: false }">
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900">CONS #{{ $consignment->id }}</p>
                            <p class="text-xs text-gray-500">{{ $consignment->received_at->format('n/j/Y') }}</p>
                        </td>
                        <td class="px-5 py-3 text-gray-700">{{ $consignment->partner?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-gray-700">{{ $consignment->items->count() }}</td>
                        <td class="px-5 py-3 text-right font-semibold text-gray-900">₱{{ number_format((float) $consignment->items->sum('line_total'), 2) }}</td>
                        <td class="px-5 py-3 text-right">
                            <button @click="details = true" title="View consignment" class="text-gray-400 hover:text-gray-700">
                                <x-icon name="eye" class="h-4 w-4" />
                            </button>
                        </td>

                        <template x-teleport="body">
                            <div x-show="details" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                                <div @click.outside="details = false" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                                    <div class="mb-4 flex items-center justify-between">
                                        <h2 class="text-lg font-semibold text-gray-900">CONS #{{ $consignment->id }} &middot; {{ $consignment->partner?->name ?? 'No partner' }}</h2>
                                        <button @click="details = false" class="text-gray-400 hover:text-gray-600">
                                            <x-icon name="x" class="h-5 w-5" />
                                        </button>
                                    </div>

                                    <p class="text-sm text-gray-600">Received {{ $consignment->received_at->format('n/j/Y') }}{{ $consignment->note ? ' &middot; '.$consignment->note : '' }}</p>

                                    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                                    <th class="px-4 py-2">Product</th>
                                                    <th class="px-4 py-2">Qty</th>
                                                    <th class="px-4 py-2 text-right">Consignment price</th>
                                                    <th class="px-4 py-2 text-right">Line total</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                @foreach ($consignment->items as $item)
                                                    <tr>
                                                        <td class="px-4 py-2 font-medium text-gray-900">{{ $item->product->name }}</td>
                                                        <td class="px-4 py-2 text-gray-700">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} {{ $item->unit?->abbreviation ?? $item->product?->unit }}</td>
                                                        <td class="px-4 py-2 text-right text-gray-700">₱{{ number_format((float) $item->unit_cost, 2) }}</td>
                                                        <td class="px-4 py-2 text-right font-semibold text-gray-900">₱{{ number_format((float) $item->line_total, 2) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                            <tfoot>
                                                <tr class="border-t border-gray-200 bg-gray-50">
                                                    <td colspan="3" class="px-4 py-2 text-right text-sm font-medium text-gray-700">Total</td>
                                                    <td class="px-4 py-2 text-right font-bold text-gray-900">₱{{ number_format((float) $consignment->items->sum('line_total'), 2) }}</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-8 text-center text-gray-500">No consignments received yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
    <script>
        window.consignmentProducts = {{ Illuminate\Support\Js::from($productRows) }};
        window.consignmentPartners = {{ Illuminate\Support\Js::from($partnerRows) }};

        function consignmentForm() {
            const emptyRow = () => ({ product_id: '', quantity: '', unit_id: '', unit_cost: '' });

            return {
                products: window.consignmentProducts.map((p) => ({ ...p, id: Number(p.id) })),
                partners: window.consignmentPartners,
                form: {
                    partner_id: '',
                    received_at: new Date().toISOString().slice(0, 10),
                    note: '',
                },
                rows: [emptyRow()],
                rowProduct(row) {
                    return this.products.find((p) => p.id === Number(row.product_id)) || null;
                },
                rowUnits(row) {
                    return this.rowProduct(row)?.units || [];
                },
                pickProduct(row) {
                    const product = this.rowProduct(row);
                    const units = product?.units || [];
                    const base = units.find((u) => u.base) || units[0];
                    row.unit_id = base ? String(base.id) : '';
                },
                lineTotal(row) {
                    return ((Number(row.quantity) || 0) * (Number(row.unit_cost) || 0)).toFixed(2);
                },
                formTotal() {
                    return this.rows
                        .reduce((sum, row) => sum + (Number(row.quantity) || 0) * (Number(row.unit_cost) || 0), 0)
                        .toFixed(2);
                },
                addRow() {
                    this.rows.push(emptyRow());
                },
            };
        }
        window.consignmentForm = consignmentForm;
    </script>
@endpush