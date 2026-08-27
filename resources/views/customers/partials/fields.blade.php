@php $customer = $customer ?? null; @endphp

<div>
    <label class="block text-sm font-medium text-gray-700">Name</label>
    <input type="text" name="name" value="{{ old('name', $customer?->name) }}" required
        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Farm name</label>
    <input type="text" name="farm_name" value="{{ old('farm_name', $customer?->farm_name) }}"
        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Phone</label>
        <input type="text" name="phone" value="{{ old('phone', $customer?->phone) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Location</label>
        <input type="text" name="location" value="{{ old('location', $customer?->location) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Crop</label>
        <input type="text" name="crop" value="{{ old('crop', $customer?->crop) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Hectares</label>
        <input type="number" step="0.01" min="0" name="hectares" value="{{ old('hectares', $customer?->hectares) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Notes</label>
    <textarea name="notes" rows="2"
        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">{{ old('notes', $customer?->notes) }}</textarea>
</div>
