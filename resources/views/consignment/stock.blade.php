@extends('layouts.app')

@section('title', 'Consignment Stock')
@section('heading', 'Consignment Stock')
@section('subheading', 'Consigned goods held per partner, priced at the weighted consignment cost.')

@php
    $productRows = $products->map(fn ($product) => [
        'id' => $product->id,
        'name' => $product->name,
        'baseUnitId' => $product->baseUnit?->id ?? null,
        'partnerIds' => $receivedByProduct[$product->id] ?? [],
        'onHandByPartner' => $onHandByProductPartner[$product->id] ?? [],
        'units' => $product->sellingUnits->map(fn ($pu) => [
            'id' => $pu->unit_id,
            'abbreviation' => $pu->unit->abbreviation,
            'conversion' => (float) $pu->conversion_to_base,
            'base' => (bool) $pu->is_base,
        ])->values()->all(),
    ])->values()->all();
@endphp

@section('actions')
    <div x-data="{ adjust: false }">
        <button @click="adjust = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="plus" class="h-4 w-4" />
            Record adjustment
        </button>

        <template x-teleport="body">
            <div x-show="adjust" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                <div @click.outside="adjust = false" class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Record consignment adjustment</h2>
                        <button @click="adjust = false" class="text-gray-400 hover:text-gray-600">
                            <x-icon name="x" class="h-5 w-5" />
                        </button>
                    </div>

                    <p class="mb-4 text-sm text-gray-600">
                        Returns cost the store nothing. Damaged, expired, or lost items are written off at the consignment price owed to the partner.
                    </p>

                    <form method="POST" action="{{ route('consignment.adjustments.store') }}" class="space-y-4" x-data="adjustmentForm()">
                        @csrf

                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="partner_id">Partner</label>
                            <select name="partner_id" x-model="form.partner_id" @change="pickPartner()" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="">Select partner...</option>
                                @foreach ($partners as $partner)
                                    <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="product_id">Product</label>
                                <select name="product_id" x-model="form.product_id" @change="pickProduct()" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                    <option value="">Select...</option>
                                    <template x-for="product in availableProducts()" :key="product.id">
                                        <option :value="product.id" x-text="product.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="reason">Reason</label>
                                <select name="reason" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                    <option value="return">Return to partner</option>
                                    <option value="damaged">Damaged</option>
                                    <option value="expired">Expired</option>
                                    <option value="lost">Lost</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="quantity">Quantity</label>
                                <input type="number" step="0.01" min="0.01" :max="maxQuantity()" name="quantity" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <p class="mt-1 text-xs text-gray-500" x-text="onHandLabel()" x-show="form.onHand"></p>
                            </div>
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="unit_id">Unit</label>
                                <select name="unit_id" x-model="form.unit_id" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                    <template x-for="unit in form.units" :key="unit.id">
                                        <option :value="unit.id" x-text="unit.abbreviation"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="adjusted_at">Date</label>
                                <input type="date" name="adjusted_at" :value="form.date" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="note">Note</label>
                            <input type="text" name="note" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>

                        <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                            Record adjustment
                        </button>
                    </form>
                </div>
            </div>
        </template>
    </div>
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Lines on hand</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $rows->count() }}</p>
        </div>
        <div class="col-span-2 rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Consigned stock value</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700">₱{{ number_format($onHandValue, 2) }}</p>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Partner</th>
                    <th class="px-5 py-3">Product</th>
                    <th class="px-5 py-3 text-right">On hand</th>
                    <th class="px-5 py-3 text-right">Avg consignment cost</th>
                    <th class="px-5 py-3 text-right">Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-5 py-3 font-medium text-gray-900">{{ $row['partner'] }}</td>
                        <td class="px-5 py-3 text-gray-700">{{ $row['product']->name }}</td>
                        <td class="px-5 py-3 text-right text-gray-900">{{ rtrim(rtrim(number_format($row['onHand'], 2), '0'), '.') }} {{ $row['product']->unit }}</td>
                        <td class="px-5 py-3 text-right text-gray-700">₱{{ number_format($row['avgCost'], 2) }}</td>
                        <td class="px-5 py-3 text-right font-semibold text-gray-900">₱{{ number_format($row['value'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-8 text-center text-gray-500">No consigned stock on hand.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-8">
        <h2 class="font-semibold text-gray-900">Recent adjustments</h2>

        <div class="mt-3 overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3">Partner</th>
                        <th class="px-5 py-3">Product</th>
                        <th class="px-5 py-3">Qty</th>
                        <th class="px-5 py-3">Reason</th>
                        <th class="px-5 py-3 text-right">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($adjustments as $adjustment)
                        <tr>
                            <td class="px-5 py-3 text-gray-500">{{ $adjustment->adjusted_at->format('n/j/Y') }}</td>
                            <td class="px-5 py-3 text-gray-700">{{ $adjustment->partner?->name ?? '—' }}</td>
                            <td class="px-5 py-3 text-gray-700">{{ $adjustment->product->name }}</td>
                            <td class="px-5 py-3 text-gray-700">{{ rtrim(rtrim(number_format((float) $adjustment->quantity, 2), '0'), '.') }}</td>
                            <td class="px-5 py-3">
                                <span class="inline-block rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">{{ ucfirst($adjustment->reason) }}</span>
                            </td>
                            <td class="px-5 py-3 text-right font-semibold text-gray-900">₱{{ number_format((float) $adjustment->value, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-8 text-center text-gray-500">No adjustments recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.adjustmentProducts = {{ Illuminate\Support\Js::from($productRows) }};

        function adjustmentForm() {
            return {
                products: window.adjustmentProducts.map((p) => ({ ...p, id: Number(p.id) })),
                form: {
                    partner_id: '',
                    product_id: '',
                    unit_id: '',
                    units: [],
                    onHand: 0,
                    date: new Date().toISOString().slice(0, 10),
                },
                availableProducts() {
                    const partnerId = Number(this.form.partner_id);
                    return this.products.filter((p) => (p.partnerIds || []).includes(partnerId));
                },
                pickPartner() {
                    this.form.product_id = '';
                    this.form.units = [];
                    this.form.unit_id = '';
                    this.form.onHand = 0;
                },
                maxQuantity() {
                    const unit = this.form.units.find((u) => u.id === Number(this.form.unit_id));
                    const conversion = unit ? Number(unit.conversion) : 1;
                    return this.form.onHand / (conversion || 1);
                },
                onHandLabel() {
                    return 'On hand: ' + (Number(this.form.onHand) || 0).toFixed(2) + ' base units';
                },
                pickProduct() {
                    const product = this.products.find((p) => p.id === Number(this.form.product_id)) || null;
                    const units = product?.units || [];
                    const base = units.find((u) => u.base) || units[0];
                    const partnerId = Number(this.form.partner_id);
                    this.form.units = units;
                    this.form.unit_id = base ? String(base.id) : '';
                    this.form.onHand = product ? (Number(product.onHandByPartner?.[partnerId]) || 0) : 0;
                },
            };
        }
        window.adjustmentForm = adjustmentForm;
    </script>
@endpush