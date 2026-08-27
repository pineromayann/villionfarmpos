@php $product = $product ?? null; @endphp

<div>
    <label class="block text-sm font-medium text-gray-700">Name</label>
    <input type="text" name="name" value="{{ old('name', $product?->name) }}" required
        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Active ingredient</label>
    <input type="text" name="active_ingredient" value="{{ old('active_ingredient', $product?->active_ingredient) }}"
        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Batch number</label>
        <input type="text" name="batch_number" value="{{ old('batch_number', $product?->batch_number) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Expiry date</label>
        <input type="date" name="expiry_date" value="{{ old('expiry_date', $product?->expiry_date?->format('Y-m-d')) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
</div>

<div class="grid grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Price</label>
        <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $product?->price) }}" required
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Stock</label>
        <input type="number" step="0.01" min="0" name="stock" value="{{ old('stock', $product?->stock) }}" required
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Unit</label>
        <input type="text" name="unit" value="{{ old('unit', $product?->unit ?? 'L') }}" required
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
</div>
