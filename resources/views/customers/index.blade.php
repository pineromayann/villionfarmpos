@extends('layouts.app')

@section('title', 'Customers')
@section('heading', 'Customers')
@section('subheading', 'Farmers and farms you serve.')

@section('actions')
    <div x-data="{ open: false, search: '' }" class="flex items-center gap-3">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
                type="text"
                x-model="search"
                @input="window.dispatchEvent(new CustomEvent('customer-search', { detail: $event.target.value }))"
                placeholder="Search..."
                class="w-56 rounded-lg border border-gray-200 py-2 pl-9 pr-3 text-sm focus:border-gray-400 focus:outline-none"
            >
        </div>

        <button @click="open = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="plus" class="h-4 w-4" />
            Add customer
        </button>

        <x-modal title="Add customer" state="open">
            <form id="customer-create-form" method="POST" action="{{ route('customers.store') }}" class="space-y-4">
                @csrf
                @include('customers.partials.fields')
            </form>

            <x-slot:footer>
                <button type="submit" form="customer-create-form" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                    Add customer
                </button>
            </x-slot:footer>
        </x-modal>
    </div>
@endsection

@section('content')
    @php
        $searchRows = $customers->map(fn ($customer) => Str::lower(trim(implode(' ', array_filter([
            $customer->name,
            $customer->farm_name,
            $customer->phone,
            $customer->location,
            $customer->crop,
        ])))))->values()->all();
    @endphp

    <div
        x-data="customerTable({{ Illuminate\Support\Js::from($searchRows) }})"
        @customer-search.window="search = $event.detail"
        class="overflow-x-auto rounded-xl border border-gray-200 bg-white"
    >
        <table class="w-full min-w-[60rem] text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Customer</th>
                    <th class="px-5 py-3">Farm</th>
                    <th class="px-5 py-3">Phone</th>
                    <th class="px-5 py-3">Location</th>
                    <th class="px-5 py-3">Crop</th>
                    <th class="px-5 py-3 text-right">Hectares</th>
                    <th class="px-5 py-3 text-right">Lifetime spend</th>
                    <th class="px-5 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($customers as $customer)
                    <tr
                        x-data="{ open: false }"
                        x-show="matches({{ $loop->index }})"
                        class="transition-colors hover:bg-gray-50"
                    >
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900">{{ $customer->name }}</p>
                            @if ($customer->notes)
                                <p class="mt-0.5 max-w-[20rem] truncate text-xs text-gray-500" title="{{ $customer->notes }}">{{ $customer->notes }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-gray-700">{{ $customer->farm_name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-gray-700">{{ $customer->phone ?? '—' }}</td>
                        <td class="px-5 py-3 text-gray-700">{{ $customer->location ?? '—' }}</td>
                        <td class="px-5 py-3 text-gray-700">{{ $customer->crop ?? '—' }}</td>
                        <td class="px-5 py-3 text-right text-gray-700">
                            {{ $customer->hectares ? rtrim(rtrim(number_format((float) $customer->hectares, 2), '0'), '.') : '—' }}
                        </td>
                        <td class="whitespace-nowrap px-5 py-3 text-right font-semibold text-gray-900">
                            ₱{{ number_format($customer->lifetimeSpend(), 2) }}
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-3">
                                <button @click="open = true" title="Edit customer" class="text-gray-400 hover:text-gray-700">
                                    <x-icon name="pencil" class="h-4 w-4" />
                                </button>
                                <form method="POST" action="{{ route('customers.destroy', $customer) }}" onsubmit="return confirm('Delete this customer?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete customer" class="text-gray-400 hover:text-red-600">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </button>
                                </form>
                            </div>
                        </td>

                        <template x-teleport="body">
                            <x-modal title="Edit customer" state="open">
                                <form id="customer-edit-form-{{ $customer->id }}" method="POST" action="{{ route('customers.update', $customer) }}" class="space-y-4">
                                    @csrf
                                    @method('PUT')
                                    @include('customers.partials.fields', ['customer' => $customer])
                                </form>

                                <x-slot:footer>
                                    <button type="submit" form="customer-edit-form-{{ $customer->id }}" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                                        Save changes
                                    </button>
                                </x-slot:footer>
                            </x-modal>
                        </template>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-6 text-center text-gray-500">No customers on file yet.</td>
                    </tr>
                @endforelse

                <tr x-cloak x-show="count() === 0 && rows.length > 0">
                    <td colspan="8" class="px-5 py-6 text-center text-gray-500">No customers match your search.</td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
    <script>
        /**
         * The customer table. Rows are rendered on the server; this only keeps
         * the search text and works out which rows are still on screen.
         */
        function customerTable(rows = []) {
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
        window.customerTable = customerTable;
    </script>
@endpush
