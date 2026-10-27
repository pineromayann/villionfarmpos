@extends('layouts.app')

@section('title', 'Suppliers')
@section('heading', 'Suppliers')
@section('subheading', 'Distributors and dealers you purchase stock from.')

@section('actions')
    <div x-data="{ open: false, search: '' }" class="flex items-center gap-3">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
                type="text"
                x-model="search"
                @input="window.dispatchEvent(new CustomEvent('supplier-search', { detail: $event.target.value }))"
                placeholder="Search..."
                class="w-56 rounded-lg border border-gray-200 py-2 pl-9 pr-3 text-sm focus:border-gray-400 focus:outline-none"
            >
        </div>

        <button @click="open = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="plus" class="h-4 w-4" />
            Add supplier
        </button>

        <x-modal title="Add supplier" state="open">
            <form id="supplier-create-form" method="POST" action="{{ route('suppliers.store') }}" class="space-y-4">
                @csrf
                @include('suppliers.partials.fields')
            </form>

            <x-slot:footer>
                <button type="submit" form="supplier-create-form" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                    Add supplier
                </button>
            </x-slot:footer>
        </x-modal>
    </div>
@endsection

@section('content')
    @php
        $searchRows = $suppliers->map(fn ($supplier) => Str::lower(trim(implode(' ', array_filter([
            $supplier->name,
            $supplier->contact_person,
            $supplier->phone,
            $supplier->location,
        ])))))->values()->all();
    @endphp

    <div
        x-data="supplierTable({{ Illuminate\Support\Js::from($searchRows) }})"
        @supplier-search.window="search = $event.detail"
        class="overflow-x-auto rounded-xl border border-gray-200 bg-white"
    >
        <table class="w-full min-w-[64rem] text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Supplier</th>
                    <th class="px-5 py-3">Contact</th>
                    <th class="px-5 py-3">Phone</th>
                    <th class="px-5 py-3">Location</th>
                    <th class="px-5 py-3 text-right">Deliveries</th>
                    <th class="px-5 py-3 text-right">Purchase orders</th>
                    <th class="px-5 py-3 text-right">Order value</th>
                    <th class="px-5 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($suppliers as $supplier)
                    <tr
                        x-data="{ open: false }"
                        x-show="matches({{ $loop->index }})"
                        class="transition-colors hover:bg-gray-50"
                    >
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900">{{ $supplier->name }}</p>
                            @if ($supplier->note)
                                <p class="mt-0.5 max-w-[20rem] truncate text-xs text-gray-500" title="{{ $supplier->note }}">{{ $supplier->note }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-gray-700">{{ $supplier->contact_person ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-gray-700">{{ $supplier->phone ?? '—' }}</td>
                        <td class="px-5 py-3 text-gray-700">{{ $supplier->location ?? '—' }}</td>
                        <td class="px-5 py-3 text-right text-gray-700">{{ $supplier->stock_movements_count }}</td>
                        <td class="px-5 py-3 text-right text-gray-700">{{ $supplier->purchase_orders_count }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right font-semibold text-gray-900">
                            ₱{{ number_format($supplier->purchase_orders_total ?? 0, 2) }}
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-3">
                                <button @click="open = true" title="Edit supplier" class="text-gray-400 hover:text-gray-700">
                                    <x-icon name="pencil" class="h-4 w-4" />
                                </button>
                                <form method="POST" action="{{ route('suppliers.destroy', $supplier) }}" onsubmit="return confirm('Delete this supplier?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete supplier" class="text-gray-400 hover:text-red-600">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </button>
                                </form>
                            </div>
                        </td>

                        <template x-teleport="body">
                            <x-modal title="Edit supplier" state="open">
                                <form id="supplier-edit-form-{{ $supplier->id }}" method="POST" action="{{ route('suppliers.update', $supplier) }}" class="space-y-4">
                                    @csrf
                                    @method('PUT')
                                    @include('suppliers.partials.fields', ['supplier' => $supplier])
                                </form>

                                <x-slot:footer>
                                    <button type="submit" form="supplier-edit-form-{{ $supplier->id }}" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                                        Save changes
                                    </button>
                                </x-slot:footer>
                            </x-modal>
                        </template>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-6 text-center text-gray-500">No suppliers on file yet.</td>
                    </tr>
                @endforelse

                <tr x-cloak x-show="count() === 0 && rows.length > 0">
                    <td colspan="8" class="px-5 py-6 text-center text-gray-500">No suppliers match your search.</td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
    <script>
        /**
         * The supplier table. Rows are rendered on the server; this only keeps
         * the search text and works out which rows are still on screen.
         */
        function supplierTable(rows = []) {
            return {
                rows,
                search: '',
                needle() {
                    return this.search.trim().toLowerCase();
                },
                matches(index) {
                    const needle = this.needle();
                    if (! needle) return true;
                    const row = this.rows[index];
                    return row ? row.includes(needle) : true;
                },
                count() {
                    const needle = this.needle();
                    if (! needle) return this.rows.length;
                    return this.rows.filter((row) => row.includes(needle)).length;
                },
            };
        }
        window.supplierTable = supplierTable;
    </script>
@endpush
