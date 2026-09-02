@extends('layouts.app')

@section('title', 'Inventory')
@section('heading', 'Inventory')
@section('subheading', 'Insecticide stock, batch numbers and expiry tracking.')

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
    <div x-data="{ search: '' }">
        <div class="mb-4 flex justify-end">
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
                        <th class="px-5 py-3">Batch</th>
                        <th class="px-5 py-3">Expiry</th>
                        <th class="px-5 py-3">Price</th>
                        <th class="px-5 py-3">Stock</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $product)
                        <tr
                            x-data="{ open: false }"
                            x-show="!search || {{ Illuminate\Support\Js::from(Str::lower($product->name.' '.$product->active_ingredient)) }}.includes(search.toLowerCase())"
                        >
                            <td class="px-5 py-4">
                                <p class="font-semibold text-gray-900">{{ $product->name }}</p>
                                <p class="text-xs text-sky-600">{{ $product->active_ingredient }}</p>
                            </td>
                            <td class="px-5 py-4 text-sky-600">{{ $product->batch_number }}</td>
                            <td class="px-5 py-4">
                                <span class="text-gray-700">{{ $product->expiry_date?->format('n/j/Y') }}</span>
                                @if ($product->isExpiringSoon())
                                    <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">soon</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-gray-700">₱{{ number_format($product->price, 2) }}</td>
                            <td class="px-5 py-4 {{ $product->isLowStock() ? 'font-medium text-red-600' : 'text-gray-700' }}">
                                {{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
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
                            <td colspan="6" class="px-5 py-8 text-center text-gray-500">No products in inventory yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
