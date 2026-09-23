@extends('layouts.app')

@section('title', 'Stock')
@section('heading', 'Stock movements')
@section('subheading', 'Every stock in, out, sale and return recorded against products.')

@section('actions')
    <div
        x-data="{
            stockIn: false,
            stockOut: false,
            products: {{ Illuminate\Support\Js::from($products->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'stock' => (float) $product->stock,
                'unit' => $product->unit,
                'units' => $product->sellingUnits->map(fn ($pu) => [
                    'id' => $pu->unit_id,
                    'name' => $pu->unit->name,
                    'abbreviation' => $pu->unit->abbreviation,
                    'base' => (bool) $pu->is_base,
                ])->values()->all(),
            ])->values()->all()) }},
            selectedProductId: '',
            unitIn: '',
            unitOut: '',
            get selectedProduct() {
                return this.products.find((p) => Number(p.id) === Number(this.selectedProductId)) || null;
            },
            pickProduct() {
                const units = this.selectedProduct?.units || [];
                const base = units.find((u) => u.base) || units[0];
                this.unitIn = base ? String(base.id) : '';
                this.unitOut = base ? String(base.id) : '';
            },
        }"
        class="flex items-center gap-3"
    >
        <button @click="stockIn = true" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
            <x-icon name="plus" class="h-4 w-4" />
            Stock in
        </button>
        <button @click="stockOut = true" class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">
            <x-icon name="minus" class="h-4 w-4" />
            Stock out
        </button>

        <template x-teleport="body">
            <div x-show="stockIn" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                <div @click.outside="stockIn = false" class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Stock in</h2>
                        <button @click="stockIn = false" class="text-gray-400 hover:text-gray-600">
                            <x-icon name="x" class="h-5 w-5" />
                        </button>
                    </div>

                    <form method="POST" action="{{ route('stock.in.store') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="product_in">Product</label>
                            <select name="product_id" id="product_in" x-model="selectedProductId" @change="pickProduct()" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="">Select product</option>
                                <template x-for="product in products" :key="product.id">
                                    <option :value="String(product.id)" x-text="product.name"></option>
                                </template>
                            </select>
                        </div>
                        <p class="text-sm text-gray-600" x-show="selectedProduct">
                            Adding stock to <span class="font-medium text-gray-900" x-text="selectedProduct.name"></span>.
                            Currently <span x-text="selectedProduct.stock"></span> <span x-text="selectedProduct.unit"></span>.
                        </p>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="quantity_in">Quantity</label>
                            <input type="number" name="quantity" id="quantity_in" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="unit_in">Unit</label>
                            <select name="unit_id" id="unit_in" x-model="unitIn" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <template x-for="unit in (selectedProduct?.units || [])" :key="unit.id">
                                    <option :value="String(unit.id)" x-text="unit.name + ' (' + unit.abbreviation + ')'"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="unit_cost">Unit cost (₱)</label>
                            <input type="number" name="unit_cost" id="unit_cost" min="0" step="0.01" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="supplier_id">Supplier</label>
                            <select name="supplier_id" id="supplier_id" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="">No supplier</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="reason_in">Reason</label>
                            <input type="text" name="reason" id="reason_in" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>

                        <button type="submit" class="w-full rounded-lg bg-emerald-700 py-2.5 text-sm font-medium text-white hover:bg-emerald-800">
                            Add stock
                        </button>
                    </form>
                </div>
            </div>
        </template>

        <template x-teleport="body">
            <div x-show="stockOut" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                <div @click.outside="stockOut = false" class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Stock out</h2>
                        <button @click="stockOut = false" class="text-gray-400 hover:text-gray-600">
                            <x-icon name="x" class="h-5 w-5" />
                        </button>
                    </div>

                    <form method="POST" action="{{ route('stock.out.store') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="product_out">Product</label>
                            <select name="product_id" id="product_out" x-model="selectedProductId" @change="pickProduct()" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="">Select product</option>
                                <template x-for="product in products" :key="product.id">
                                    <option :value="String(product.id)" x-text="product.name"></option>
                                </template>
                            </select>
                        </div>
                        <p class="text-sm text-gray-600" x-show="selectedProduct">
                            Removing stock from <span class="font-medium text-gray-900" x-text="selectedProduct.name"></span>.
                            <span x-text="selectedProduct.stock"></span> <span x-text="selectedProduct.unit"></span> available.
                        </p>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="quantity_out">Quantity</label>
                            <input type="number" name="quantity" id="quantity_out" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="unit_out">Unit</label>
                            <select name="unit_id" id="unit_out" x-model="unitOut" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <template x-for="unit in (selectedProduct?.units || [])" :key="unit.id">
                                    <option :value="String(unit.id)" x-text="unit.name + ' (' + unit.abbreviation + ')'"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="reason_out">Reason</label>
                            <select name="reason" id="reason_out" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                <option value="damaged">Damaged</option>
                                <option value="expired">Expired</option>
                                <option value="lost">Lost</option>
                                <option value="shrinkage">Shrinkage</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <button type="submit" class="w-full rounded-lg bg-red-700 py-2.5 text-sm font-medium text-white hover:bg-red-800">
                            Remove stock
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
                            @if ($movement->ref_type === 'procurement')
                                <a href="{{ route('procurement.index') }}" class="text-sky-600 hover:underline">PO #{{ $movement->ref_id }}</a>
                                @if ($movement->supplier)
                                    &middot; {{ $movement->supplier->name }}
                                @endif
                            @elseif ($movement->ref_type === 'sale')
                                <a href="{{ route('sales.index') }}" class="text-sky-600 hover:underline">Sale #{{ $movement->ref_id }}</a>
                            @elseif ($movement->supplier)
                                {{ $movement->supplier->name }}
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