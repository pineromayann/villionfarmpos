@extends('layouts.app')

@section('title', 'Procurement')
@section('heading', 'Procurement')
@section('subheading', 'Purchase orders raised against your suppliers, and received into stock.')

@php
    $productRows = $products->map(fn ($product) => [
        'id' => $product->id,
        'name' => $product->name,
        'cost' => $product->cost_price !== null ? (float) $product->cost_price : null,
        'baseUnitId' => $product->baseUnit?->id ?? null,
        'units' => $product->sellingUnits->map(fn ($pu) => [
            'id' => $pu->unit_id,
            'name' => $pu->unit->name,
            'abbreviation' => $pu->unit->abbreviation,
            'base' => (bool) $pu->is_base,
        ])->values()->all(),
    ])->values()->all();

    $supplierRows = $suppliers->map(fn ($supplier) => [
        'id' => $supplier->id,
        'name' => $supplier->name,
    ])->values()->all();
@endphp

@section('actions')
    <div x-data="{ create: false, search: '' }" class="flex items-center gap-3">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
                type="text"
                x-model="search"
                @input="window.dispatchEvent(new CustomEvent('procurement-search', { detail: $event.target.value }))"
                placeholder="Search orders..."
                class="w-56 rounded-lg border border-gray-200 py-2 pl-9 pr-3 text-sm focus:border-gray-400 focus:outline-none"
            >
        </div>

        <button @click="create = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="plus" class="h-4 w-4" />
            New order
        </button>

        <template x-teleport="body">
            <x-modal title="New purchase order" state="create" max-width="max-w-2xl">
                @include('procurement.partials.order-form', [
                    'formAction' => route('procurement.store'),
                    'formMethod' => 'POST',
                    'formId' => 'po-create-form',
                    'orderConfig' => [
                        'order' => [
                            'supplier_id' => '',
                            'order_date' => now()->toDateString(),
                            'expected_date' => '',
                            'note' => '',
                        ],
                        'rows' => [
                            ['product_id' => '', 'quantity' => '', 'unit_id' => '', 'unit_cost' => ''],
                        ],
                    ],
                ])

                <x-slot:footer>
                    <button type="submit" form="po-create-form" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                        Create order
                    </button>
                </x-slot:footer>
            </x-modal>
        </template>
    </div>
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-sky-600">Pending orders</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $pendingOrders }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Purchases this month</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700">₱{{ number_format($spentThisMonth, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Lifetime purchases</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">₱{{ number_format($lifetimeSpend, 2) }}</p>
        </div>
    </div>

    <div x-data="{ search: '' }" @procurement-search.window="search = $event.detail" class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="px-5 py-3">Order</th>
                    <th class="px-5 py-3">Supplier</th>
                    <th class="px-5 py-3">Products</th>
                    <th class="px-5 py-3">Total</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($orders as $order)
                    @php
                        $searchText = Str::lower('PO #'.$order->id.' '.$order->status.' '.($order->supplier?->name ?? ''));
                    @endphp
                    <tr x-data="{ details: false, edit: false, receive: false }" x-show="!search || {{ Illuminate\Support\Js::from($searchText) }}.includes(search.toLowerCase())">
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900">PO #{{ $order->id }}</p>
                            <p class="text-xs text-gray-500">{{ $order->order_date->format('n/j/Y') }}{{ $order->expected_date ? ' &middot; expected '.$order->expected_date->format('n/j/Y') : '' }}</p>
                        </td>
                        <td class="px-5 py-3 text-gray-700">{{ $order->supplier?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-gray-700">{{ $order->itemCount() }}</td>
                        <td class="px-5 py-3 font-semibold text-gray-900">₱{{ number_format((float) $order->total, 2) }}</td>
                        <td class="px-5 py-3">
                            @if ($order->isOrdered())
                                <span class="inline-block rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">Ordered</span>
                            @elseif ($order->isReceived())
                                <span class="inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Received</span>
                            @else
                                <span class="inline-block rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Cancelled</span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-3">
                                <button @click="details = true" title="View order" class="text-gray-400 hover:text-gray-700">
                                    <x-icon name="eye" class="h-4 w-4" />
                                </button>
                                @if ($order->isOrdered())
                                    <button @click="edit = true" title="Edit order" class="text-gray-400 hover:text-gray-700">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                    </button>
                                    <button @click="receive = true" title="Receive order" class="text-emerald-600 hover:text-emerald-800">
                                        <x-icon name="check" class="h-4 w-4" />
                                    </button>
                                    <form method="POST" action="{{ route('procurement.cancel', $order) }}" onsubmit="return confirm('Cancel PO #{{ $order->id }}?')">
                                        @csrf
                                        <button type="submit" title="Cancel order" class="text-gray-400 hover:text-red-600">
                                            <x-icon name="x" class="h-4 w-4" />
                                        </button>
                                    </form>
                                @endif
                                @unless ($order->isReceived())
                                    <form method="POST" action="{{ route('procurement.destroy', $order) }}" onsubmit="return confirm('Delete PO #{{ $order->id }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" title="Delete order" class="text-gray-400 hover:text-red-600">
                                            <x-icon name="trash" class="h-4 w-4" />
                                        </button>
                                    </form>
                                @endunless
                            </div>
                        </td>

                        <template x-teleport="body">
                            <x-modal title="PO #{{ $order->id }}" state="details" max-width="max-w-lg">
                                <div class="space-y-1.5 text-sm text-gray-600">
                                    <p><span class="text-gray-400">Supplier:</span> {{ $order->supplier?->name ?? '—' }}</p>
                                    <p><span class="text-gray-400">Ordered:</span> {{ $order->order_date->format('n/j/Y') }}</p>
                                    @if ($order->expected_date)
                                        <p><span class="text-gray-400">Expected:</span> {{ $order->expected_date->format('n/j/Y') }}</p>
                                    @endif
                                    @if ($order->received_at)
                                        <p><span class="text-gray-400">Received:</span> {{ $order->received_at->format('n/j/Y, g:i A') }}</p>
                                    @endif
                                    @if ($order->note)
                                        <p><span class="text-gray-400">Note:</span> {{ $order->note }}</p>
                                    @endif
                                </div>

                                <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200">
                                    <table class="w-full min-w-[26rem] text-sm">
                                        <thead>
                                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                                <th class="px-4 py-2">Product</th>
                                                <th class="px-4 py-2">Qty</th>
                                                <th class="px-4 py-2 text-right">Unit cost</th>
                                                <th class="px-4 py-2 text-right">Line total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($order->items as $item)
                                                <tr>
                                                    <td class="px-4 py-2 font-medium text-gray-900">{{ $item->product->name }}</td>
                                                    <td class="px-4 py-2 text-gray-700">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} {{ $item->unit?->abbreviation ?? $item->product?->unit }}</td>
                                                    <td class="px-4 py-2 text-right text-gray-700">₱{{ number_format((float) $item->unit_cost, 2) }}</td>
                                                    <td class="px-4 py-2 text-right font-semibold text-gray-900">₱{{ number_format((float) $item->line_total, 2) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-t border-gray-200 bg-gray-50">
                                                <td colspan="3" class="px-4 py-2 text-right text-sm font-medium text-gray-700">Total</td>
                                                <td class="px-4 py-2 text-right font-bold text-gray-900">₱{{ number_format((float) $order->total, 2) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </x-modal>
                        </template>

                        @if ($order->isOrdered())
                            <template x-teleport="body">
                                <x-modal title="Edit PO #{{ $order->id }}" state="edit" max-width="max-w-2xl">
                                    @include('procurement.partials.order-form', [
                                        'formAction' => route('procurement.update', $order),
                                        'formMethod' => 'PUT',
                                        'formId' => 'po-edit-form-'.$order->id,
                                        'orderConfig' => [
                                            'order' => [
                                                'supplier_id' => $order->supplier_id !== null ? (string) $order->supplier_id : '',
                                                'order_date' => $order->order_date->format('Y-m-d'),
                                                'expected_date' => $order->expected_date?->format('Y-m-d') ?? '',
                                                'note' => $order->note ?? '',
                                            ],
                                            'rows' => $order->items->map(fn ($item) => [
                                                'product_id' => (string) $item->product_id,
                                                'quantity' => (float) $item->quantity,
                                                'unit_id' => $item->unit_id !== null ? (string) $item->unit_id : '',
                                                'unit_cost' => (float) $item->unit_cost,
                                            ])->values()->all(),
                                        ],
                                    ])

                                    <x-slot:footer>
                                        <button type="submit" form="po-edit-form-{{ $order->id }}" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                                            Save changes
                                        </button>
                                    </x-slot:footer>
                                </x-modal>
                            </template>

                            <template x-teleport="body">
                                <x-modal title="Receive PO #{{ $order->id }}" state="receive" max-width="max-w-md">
                                    <p class="text-sm text-gray-600">
                                        Receiving this order from <span class="font-medium text-gray-900">{{ $order->supplier?->name ?? 'an unspecified supplier' }}</span>
                                        will add {{ $order->itemCount() }} product{{ $order->itemCount() === 1 ? '' : 's' }} to stock:
                                    </p>

                                    <ul class="mt-3 space-y-1.5 text-sm text-gray-700">
                                        @foreach ($order->items as $item)
                                            <li class="flex items-center justify-between gap-3">
                                                <span>{{ $item->product->name }}</span>
                                                <span class="font-medium">
                                                    +{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} {{ $item->unit?->abbreviation ?? $item->product?->unit }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <form id="po-receive-form-{{ $order->id }}" method="POST" action="{{ route('procurement.receive', $order) }}">
                                        @csrf
                                    </form>

                                    <x-slot:footer>
                                        <button type="submit" form="po-receive-form-{{ $order->id }}" class="w-full rounded-lg bg-emerald-700 py-2.5 text-sm font-medium text-white hover:bg-emerald-800">
                                            Receive order (₱{{ number_format((float) $order->total, 2) }})
                                        </button>
                                    </x-slot:footer>
                                </x-modal>
                            </template>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-8 text-center text-gray-500">No purchase orders yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
    <script>
        window.procurementProducts = {{ Illuminate\Support\Js::from($productRows) }};
        window.procurementSuppliers = {{ Illuminate\Support\Js::from($supplierRows) }};

        function orderForm({ products = [], suppliers = [], order, rows = [] }) {
            const emptyRow = () => ({ product_id: '', quantity: '', unit_id: '', unit_cost: '' });

            return {
                products: products.map((p) => ({ ...p, id: Number(p.id) })),
                suppliers,
                order: {
                    supplier_id: '',
                    order_date: new Date().toISOString().slice(0, 10),
                    expected_date: '',
                    note: '',
                    ...order,
                },
                rows: [...rows].map((row) => ({
                    product_id: row.product_id != null ? String(row.product_id) : '',
                    quantity: row.quantity ?? '',
                    unit_id: row.unit_id != null ? String(row.unit_id) : '',
                    unit_cost: row.unit_cost ?? '',
                })),
                rowProduct(row) {
                    return this.products.find((p) => p.id === Number(row.product_id)) || null;
                },
                rowUnits(row) {
                    return this.rowProduct(row)?.units || [];
                },
                pickProduct(row) {
                    const product = this.rowProduct(row);
                    const units = product?.units || [];
                    const base = units.find((u) => u.base) || units[0];
                    row.unit_id = base ? String(base.id) : '';
                    if (!row.unit_cost && product?.cost != null) {
                        row.unit_cost = product.cost;
                    }
                },
                lineTotal(row) {
                    return ((Number(row.quantity) || 0) * (Number(row.unit_cost) || 0)).toFixed(2);
                },
                formTotal() {
                    return this.rows
                        .reduce((sum, row) => sum + (Number(row.quantity) || 0) * (Number(row.unit_cost) || 0), 0)
                        .toFixed(2);
                },
                addRow() {
                    this.rows.push(emptyRow());
                },
                removeRow(index) {
                    this.rows.splice(index, 1);
                },
            };
        }
        window.orderForm = orderForm;
    </script>
@endpush