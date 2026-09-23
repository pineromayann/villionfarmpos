@extends('layouts.app')

@section('title', 'Inventory')
@section('heading', 'Inventory')
@section('subheading', 'Pesticide stock, categories, pricing tiers and expiry tracking.')

@section('actions')
    <div x-data="{ open: false }" class="flex items-center gap-3">
        <button @click="open = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="plus" class="h-4 w-4" />
            Add product
        </button>

        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <div @click.outside="open = false" class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Add product</h2>
                    <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                        <x-icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                <form method="POST" action="{{ route('inventory.store') }}" class="space-y-4">
                    @csrf
                    @include('inventory.partials.fields')

                    <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                        Add product
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div x-data="{ search: '', category: '' }">
        <div class="mb-4 flex items-center justify-end gap-3">
            <select
                x-model="category"
                class="rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
            >
                <option value="">All categories</option>
                @foreach (\App\Models\Product::CATEGORIES as $category)
                    <option value="{{ $category }}">{{ ucfirst($category) }}</option>
                @endforeach
            </select>

            <div class="relative w-full max-w-xs">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                <input
                    type="text"
                    x-model="search"
                    placeholder="Search..."
                    class="w-full rounded-lg border border-gray-200 py-2 pl-9 pr-3 text-sm focus:border-gray-400 focus:outline-none"
                >
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                        <th class="px-5 py-3">Product</th>
                        <th class="px-5 py-3">Category</th>
                        <th class="px-5 py-3">Batch</th>
                        <th class="px-5 py-3">Expiry</th>
                        <th class="px-5 py-3">Cost</th>
                        <th class="px-5 py-3">Dealer price</th>
                        <th class="px-5 py-3">Stock</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $product)
                        <tr
                            x-data="{ open: false, stockIn: false, stockOut: false }"
                            x-show="(!search || {{ Illuminate\Support\Js::from(Str::lower($product->name.' '.$product->category.' '.$product->active_ingredient)) }}.includes(search.toLowerCase())) && (category === '' || category === {{ Illuminate\Support\Js::from($product->category ?? null) }})"
                        >
                            <td class="px-5 py-4">
                                <p class="font-semibold text-gray-900">{{ $product->name }}</p>
                                <p class="text-xs text-sky-600">{{ $product->active_ingredient }}</p>
                                @if ($product->note)
                                    <p class="mt-0.5 text-xs text-amber-600">{{ $product->note }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ ucfirst($product->category ?? '—') }}</span>
                            </td>
                            <td class="px-5 py-4 text-sky-600">{{ $product->batch_number }}</td>
                            <td class="px-5 py-4">
                                <span class="text-gray-700">{{ $product->expiry_date?->format('n/j/Y') }}</span>
                                @if ($product->isExpiringSoon())
                                    <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">soon</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-gray-700">{{ $product->cost_price !== null ? '₱'.number_format($product->cost_price, 2) : '—' }}</td>
                            <td class="px-5 py-4 text-gray-700">
                                <p>₱{{ number_format($product->salePrice(), 2) }}</p>
                                @if ($product->dealers_price_cod !== null || $product->terms_30_days !== null)
                                    <p class="text-xs text-gray-400">
                                        COD ₱{{ number_format($product->dealers_price_cod ?? 0, 2) }} &middot; 30d ₱{{ number_format($product->terms_30_days ?? 0, 2) }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-5 py-4 {{ $product->isLowStock() ? 'font-medium text-red-600' : 'text-gray-700' }}">
                                {{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    <button @click="stockIn = true" title="Stock in" class="text-emerald-500 hover:text-emerald-700">
                                        <x-icon name="plus" class="h-4 w-4" />
                                    </button>
                                    <button @click="stockOut = true" title="Stock out" class="text-red-500 hover:text-red-600">
                                        <x-icon name="minus" class="h-4 w-4" />
                                    </button>
                                    <button @click="open = true" class="text-gray-400 hover:text-gray-700">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                    </button>
                                    <form method="POST" action="{{ route('inventory.destroy', $product) }}" onsubmit="return confirm('Delete this product?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-gray-400 hover:text-red-600">
                                            <x-icon name="trash" class="h-4 w-4" />
                                        </button>
                                    </form>
                                </div>
                            </td>

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
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            <p class="text-sm text-gray-600">
                                                Adding stock to <span class="font-medium text-gray-900">{{ $product->name }}</span>.
                                                Currently {{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}.
                                            </p>
                                            <div>
                                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="quantity_in">Quantity</label>
                                                <input type="number" name="quantity" id="quantity_in" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                            </div>
                                            <div>
                                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="unit_in">Unit</label>
                                                <select name="unit_id" id="unit_in" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                                    @foreach ($product->sellingUnits as $productUnit)
                                                        <option value="{{ $productUnit->unit_id }}" @selected($productUnit->is_base)>
                                                            {{ $productUnit->unit->name }} ({{ $productUnit->unit->abbreviation }})
                                                        </option>
                                                    @endforeach
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
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            <p class="text-sm text-gray-600">
                                                Removing stock from <span class="font-medium text-gray-900">{{ $product->name }}</span>.
                                                {{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }} available.
                                            </p>
                                            <div>
                                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="quantity_out">Quantity</label>
                                                <input type="number" name="quantity" id="quantity_out" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                            </div>
                                            <div>
                                                <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="unit_out">Unit</label>
                                                <select name="unit_id" id="unit_out" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                                    @foreach ($product->sellingUnits as $productUnit)
                                                        <option value="{{ $productUnit->unit_id }}" @selected($productUnit->is_base)>
                                                            {{ $productUnit->unit->name }} ({{ $productUnit->unit->abbreviation }})
                                                        </option>
                                                    @endforeach
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

                            <template x-teleport="body">
                                <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                                    <div @click.outside="open = false" class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                                        <div class="mb-4 flex items-center justify-between">
                                            <h2 class="text-lg font-semibold text-gray-900">Edit product</h2>
                                            <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                                                <x-icon name="x" class="h-5 w-5" />
                                            </button>
                                        </div>

                                        <form method="POST" action="{{ route('inventory.update', $product) }}" class="space-y-4">
                                            @csrf
                                            @method('PUT')
                                            @include('inventory.partials.fields', ['product' => $product])

                                            <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                                                Save changes
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </template>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-8 text-center text-gray-500">No products in inventory yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
