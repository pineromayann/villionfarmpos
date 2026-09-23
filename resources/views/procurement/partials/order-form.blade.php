@php
    /** @var string $formAction */
    /** @var string $formMethod */
    /** @var array<string, mixed> $orderConfig */
    /** @var string $submitLabel */
@endphp

<form
    method="POST"
    action="{{ $formAction }}"
    x-data="orderForm({
        products: window.procurementProducts || [],
        suppliers: window.procurementSuppliers || [],
        order: {{ Illuminate\Support\Js::from($orderConfig['order']) }},
        rows: {{ Illuminate\Support\Js::from($orderConfig['rows']) }},
    })"
    class="space-y-4"
>
    @csrf
    @if ($formMethod === 'PUT')
        @method('PUT')
    @endif

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="order_supplier">Supplier</label>
            <select name="supplier_id" id="order_supplier" x-model="order.supplier_id" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                <option value="">No supplier</option>
                <template x-for="supplier in suppliers" :key="supplier.id">
                    <option :value="String(supplier.id)" x-text="supplier.name"></option>
                </template>
            </select>
        </div>
        <div>
            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="order_date">Order date</label>
            <input type="date" name="order_date" id="order_date" x-model="order.order_date" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="expected_date">Expected date</label>
            <input type="date" name="expected_date" id="expected_date" x-model="order.expected_date" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
        </div>
        <div>
            <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="order_note">Note</label>
            <input type="text" name="note" id="order_note" x-model="order.note" maxlength="1000" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
        <div class="mb-2 flex items-center justify-between">
            <label class="block text-sm font-medium text-gray-700">Products</label>
            <button type="button" @click="addRow()" class="text-sm font-medium text-sky-600 hover:text-sky-700">+ Add product</button>
        </div>

        <template x-for="(row, index) in rows" :key="'po-item-' + index">
            <div class="mb-2 grid grid-cols-12 items-end gap-2 rounded-lg border border-gray-200 bg-white p-2">
                <div class="col-span-5">
                    <label class="text-xs text-gray-500" :for="'item_product_' + index">Product</label>
                    <select :name="'items[' + index + '][product_id]'" :id="'item_product_' + index" x-model="row.product_id" @change="pickProduct(row)" required class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                        <option value="">Select product</option>
                        <template x-for="product in products" :key="product.id">
                            <option :value="String(product.id)" x-text="product.name"></option>
                        </template>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="text-xs text-gray-500" :for="'item_qty_' + index">Quantity</label>
                    <input type="number" min="0.01" step="0.01" :name="'items[' + index + '][quantity]'" :id="'item_qty_' + index" x-model.number="row.quantity" required placeholder="0" class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                </div>
                <div class="col-span-3">
                    <label class="text-xs text-gray-500" :for="'item_unit_' + index">Unit</label>
                    <select :name="'items[' + index + '][unit_id]'" :id="'item_unit_' + index" x-model="row.unit_id" class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                        <template x-for="unit in rowUnits(row)" :key="unit.id">
                            <option :value="String(unit.id)" x-text="unit.name + ' (' + unit.abbreviation + ')'"></option>
                        </template>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="text-xs text-gray-500" :for="'item_cost_' + index">Unit cost</label>
                    <input type="number" min="0" step="0.01" :name="'items[' + index + '][unit_cost]'" :id="'item_cost_' + index" x-model.number="row.unit_cost" required placeholder="0.00" class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm focus:border-gray-400 focus:outline-none">
                </div>
                <div class="col-span-12 flex items-center justify-between pt-1 sm:col-span-1 sm:justify-end">
                    <span class="text-sm text-gray-500 sm:hidden">₱<span x-text="lineTotal(row)"></span></span>
                    <button type="button" @click="removeRow(index)" class="text-gray-400 hover:text-red-600">
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </template>

        <template x-if="rows.length === 0">
            <p class="text-sm text-gray-400">No products on this order yet.</p>
        </template>

        <div class="mt-3 flex items-center justify-between border-t border-gray-200 pt-3">
            <span class="text-sm font-medium text-gray-700">Order total</span>
            <span class="text-lg font-bold text-gray-900">₱<span x-text="formTotal()"></span></span>
        </div>
    </div>

    <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
        {{ $submitLabel }}
    </button>
</form>