@php
    $product = $product ?? null;
    $unitTypes = $unitTypes ?? collect();

    $unitsByType = $unitTypes
        ->flatMap(fn ($type) => $type->units->map(fn ($unit) => [
            'id' => (int) $unit->id,
            'name' => $unit->name,
            'abbreviation' => $unit->abbreviation,
            'type' => $type->id,
        ]))
        ->values();

    $defaultBaseId = $unitsByType->firstWhere('abbreviation', 'L')['id'] ?? $unitsByType->first()['id'] ?? null;

    $baseUnitId = old('base_unit_id', $product?->base_unit_id ?? $defaultBaseId);

    $existingRows = $product
        ? $product->sellingUnits->where('is_base', false)->map(fn ($pu) => [
            'unit_id' => (int) $pu->unit_id,
            'conversion_to_base' => number_format((float) $pu->conversion_to_base, 4),
        ])->values()->all()
        : [];

    $sellingUnitRows = old('selling_units', $existingRows);
@endphp

<div>
    <label class="block text-sm font-medium text-gray-700">Name</label>
    <input type="text" name="name" value="{{ old('name', $product?->name) }}" required
        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Category</label>
        <select name="category" required
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
            @foreach (\App\Models\Product::CATEGORIES as $category)
                <option value="{{ $category }}" @selected(old('category', $product?->category) === $category)>
                    {{ ucfirst($category) }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Active ingredient</label>
        <input type="text" name="active_ingredient" value="{{ old('active_ingredient', $product?->active_ingredient) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
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
        <label class="block text-sm font-medium text-gray-700">Cost price</label>
        <input type="number" step="0.01" min="0" name="cost_price" value="{{ old('cost_price', $product?->cost_price) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Dealer price (COD)</label>
        <input type="number" step="0.01" min="0" name="dealers_price_cod" value="{{ old('dealers_price_cod', $product?->dealers_price_cod) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Dealer price (30 days)</label>
        <input type="number" step="0.01" min="0" name="terms_30_days" value="{{ old('terms_30_days', $product?->terms_30_days) }}"
            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
    </div>
</div>

<div x-data="sellingUnitsForm({{ Illuminate\Support\Js::from([
    'baseUnitId' => (int) $baseUnitId,
    'rows' => collect($sellingUnitRows)->map(fn ($row) => [
        'unit_id' => (int) ($row['unit_id'] ?? 0),
        'conversion_to_base' => (string) ($row['conversion_to_base'] ?? '1'),
    ])->values()->all(),
    'units' => $unitsByType->all(),
    'types' => $unitTypes->map(fn ($type) => ['id' => $type->id, 'name' => $type->name])->values()->all(),
]) }})">
    <div class="grid grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">Selling price</label>
            <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $product?->price) }}"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
        </div>
        @if ($product)
            <div>
                <label class="block text-sm font-medium text-gray-700">Stock</label>
                <p class="mt-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900">
                    {{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}
                </p>
                <p class="mt-1 text-xs text-gray-400">Stock is adjusted from the Stock page.</p>
            </div>
        @else
            <div>
                <label class="block text-sm font-medium text-gray-700">Stock</label>
                <input type="number" step="0.01" min="0" name="stock" value="{{ old('stock', $product?->stock) }}" required
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
            </div>
        @endif
        <div>
            <label class="block text-sm font-medium text-gray-700">Base unit</label>
            <select name="base_unit_id" x-model="baseUnitId" @change="syncRows()" required
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                <template x-for="type in types" :key="type.id">
                    <optgroup :label="type.name">
                        <template x-for="unit in units.filter(u => u.type === type.id)" :key="unit.id">
                            <option :value="unit.id" x-text="unit.name + ' (' + unit.abbreviation + ')'"></option>
                        </template>
                    </optgroup>
                </template>
            </select>
        </div>
    </div>

    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3">
        <div class="mb-2 flex items-center justify-between">
            <label class="block text-sm font-medium text-gray-700">Selling units</label>
            <button type="button" @click="addRow()" class="text-sm font-medium text-sky-600 hover:text-sky-700">+ Add unit</button>
        </div>
        <p class="mb-3 text-xs text-gray-500">
            Extra units this product is sold in. One unit equals 1 &times; conversion of the base unit above (e.g. selling in <span x-text="unitLabel(baseUnitId)"></span>).
        </p>

        <template x-for="(row, index) in rows" :key="'selling-unit-' + index">
            <div class="mb-2 flex items-center gap-2">
                <select :name="'selling_units[' + index + '][unit_id]'" x-model="row.unit_id" required
                    class="w-40 rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                    <template x-for="unit in allowedUnits()" :key="unit.id">
                        <option :value="unit.id" x-text="unit.name + ' (' + unit.abbreviation + ')'"></option>
                    </template>
                </select>
                <input type="number" step="any" min="0.0001" :name="'selling_units[' + index + '][conversion_to_base]'" x-model="row.conversion_to_base"
                    placeholder="Conversion" required
                    class="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                <span class="text-xs text-gray-500">
                    = <span x-text="row.conversion_to_base"></span> <span x-text="unitLabel(baseUnitId)"></span>
                </span>
                <button type="button" @click="removeRow(index)" class="text-gray-400 hover:text-red-600">
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>
        </template>

        <template x-if="rows.length === 0">
            <p class="text-sm text-gray-400">No extra selling units. Product sells in its base unit.</p>
        </template>
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Note</label>
    <textarea name="note" rows="2" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">{{ old('note', $product?->note) }}</textarea>
</div>

@push('scripts')
    <script>
        function sellingUnitsForm({ baseUnitId, rows, units, types }) {
            return {
                baseUnitId: Number(baseUnitId) || null,
                units: units.map((u) => ({ ...u, id: Number(u.id) })),
                types,
                rows: (rows || []).map((row) => ({
                    unit_id: Number(row.unit_id) || null,
                    conversion_to_base: String(row.conversion_to_base || '1'),
                })),
                get baseType() {
                    const unit = this.units.find((u) => u.id === Number(this.baseUnitId));
                    return unit ? unit.type : null;
                },
                allowedUnits() {
                    return this.units.filter((u) => u.type === this.baseType);
                },
                unitLabel(id) {
                    const unit = this.units.find((u) => u.id === Number(id));
                    return unit ? `${unit.name} (${unit.abbreviation})` : '';
                },
                syncRows() {
                    const allowed = this.allowedUnits();
                    this.rows.forEach((row) => {
                        const unit = this.units.find((u) => u.id === Number(row.unit_id));
                        if (!unit || unit.type !== this.baseType) {
                            row.unit_id = (allowed.find((u) => u.id !== Number(this.baseUnitId)) || {}).id ?? null;
                        }
                    });
                },
                addRow() {
                    const candidates = this.allowedUnits().filter(
                        (u) => u.id !== Number(this.baseUnitId) && !this.rows.some((row) => Number(row.unit_id) === u.id)
                    );
                    const unit = candidates[0] || this.allowedUnits().find((u) => u.id !== Number(this.baseUnitId));
                    this.rows.push({ unit_id: unit ? unit.id : null, conversion_to_base: '1' });
                },
                removeRow(index) {
                    this.rows.splice(index, 1);
                },
            };
        }
        window.sellingUnitsForm = sellingUnitsForm;
    </script>
@endpush