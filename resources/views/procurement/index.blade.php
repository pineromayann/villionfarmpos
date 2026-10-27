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
    <div x-data="{ create: false, search: '' }" @procurement-draft.window="create = true" class="flex items-center gap-3">
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
                    'draftTarget' => true,
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

@php
    /** @var array<int, array<string, mixed>> $suggestions */
    $suggestionLimit = \App\Procurement\SuggestionService::PREVIEW_SIZE;
    $previewSuggestions = array_slice($suggestions, 0, $suggestionLimit);
@endphp

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-amber-600">Products to procure</p>
            <p class="mt-1 text-2xl font-bold {{ count($suggestions) > 0 ? 'text-amber-600' : 'text-gray-900' }}">{{ count($suggestions) }}</p>
        </div>
    </div>

    <div x-data="suggestionPanel({{ Illuminate\Support\Js::from($suggestions) }}, {{ $suggestionLimit }})" class="mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
            <div class="flex items-center gap-2">
                <x-icon name="warning" class="h-4 w-4 text-amber-500" />
                <h2 class="font-semibold text-gray-900">Suggested to procure</h2>
                <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">{{ count($suggestions) }}</span>
            </div>
            <div class="flex items-center gap-4">
                @if ($suggestionsOnOrder > 0)
                    <span class="text-xs text-gray-500">{{ $suggestionsOnOrder }} low-stock {{ Str::plural('product', $suggestionsOnOrder) }} already on order</span>
                @endif
                @if (count($suggestions) > $suggestionLimit)
                    <button type="button" @click="showAll = true" class="text-xs font-medium text-blue-600 hover:underline">
                        Show all {{ count($suggestions) }}
                    </button>
                @endif
                <label class="flex items-center gap-2 text-xs font-medium text-gray-500">
                    <input type="checkbox" @change="toggleAll()" :checked="allSelected" class="rounded border-gray-300 text-gray-900 focus:ring-gray-400">
                    Select all
                </label>
            </div>
        </div>

        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th class="w-10 px-5 py-3"><span class="sr-only">Select</span></th>
                    <th class="px-5 py-3">Product</th>
                    <th class="px-5 py-3">Category</th>
                    <th class="px-5 py-3">In stock</th>
                    <th class="px-5 py-3">Reorder at</th>
                    <th class="px-5 py-3">Suggested qty</th>
                    <th class="px-5 py-3">Est. cost</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($previewSuggestions as $index => $suggestion)
                    <tr>
                        @include('procurement.partials.suggestion-row', [
                            'suggestion' => $suggestion,
                            'index' => $index,
                        ])
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-8 text-center text-gray-500">
                            No products need restocking. Everything is above its reorder point.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if (count($suggestions) > 0)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3">
                <p class="text-xs text-gray-500">The suggested quantity covers the reorder point plus about a month of recent sales.</p>
                <button
                    type="button"
                    @click="draft()"
                    :disabled="selectedCount === 0"
                    class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <x-icon name="cart" class="h-4 w-4" />
                    Create order with <span x-text="selectedCount"></span> selected
                </button>
            </div>
        @endif

        @if (count($suggestions) > $suggestionLimit)
            <template x-teleport="body">
                <x-modal title="All products to procure ({{ count($suggestions) }})" state="showAll" max-width="max-w-4xl">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <label class="flex items-center gap-2 text-xs font-medium text-gray-500">
                            <input type="checkbox" @change="toggleEvery()" :checked="everySelected" class="rounded border-gray-300 text-gray-900 focus:ring-gray-400">
                            Select all {{ count($suggestions) }}
                        </label>
                        <span class="text-xs text-gray-500"><span x-text="selectedCount"></span> selected</span>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="w-full min-w-[42rem] text-sm">
                            <thead class="sticky top-0 z-10 bg-gray-50">
                                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                    <th class="w-10 px-4 py-2"><span class="sr-only">Select</span></th>
                                    <th class="px-4 py-2">Product</th>
                                    <th class="px-4 py-2">Category</th>
                                    <th class="px-4 py-2">In stock</th>
                                    <th class="px-4 py-2">Reorder at</th>
                                    <th class="px-4 py-2">Suggested qty</th>
                                    <th class="px-4 py-2">Est. cost</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($suggestions as $index => $suggestion)
                                    <tr>
                                        @include('procurement.partials.suggestion-row', [
                                            'suggestion' => $suggestion,
                                            'index' => $index,
                                            'cell' => 'px-4 py-2',
                                        ])
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <x-slot:footer>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-gray-500">The suggested quantity covers the reorder point plus about a month of recent sales.</p>
                            <button
                                type="button"
                                @click="draft()"
                                :disabled="selectedCount === 0"
                                class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                <x-icon name="cart" class="h-4 w-4" />
                                Create order with <span x-text="selectedCount"></span> selected
                            </button>
                        </div>
                    </x-slot:footer>
                </x-modal>
            </template>
        @endif
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

                        $receiveLines = $order->items->map(fn ($item) => [
                            'id' => $item->id,
                            'quantity' => (float) $item->quantity,
                            'unitCost' => (float) $item->unit_cost,
                        ])->values()->all();
                    @endphp
                    <tr x-data="{ details: false, edit: false, receive: false, ...receiveForm({{ Illuminate\Support\Js::from($receiveLines) }}) }" x-show="!search || {{ Illuminate\Support\Js::from($searchText) }}.includes(search.toLowerCase())">
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900">PO #{{ $order->id }}</p>
                            <p class="text-xs text-gray-500">{{ $order->order_date->format('n/j/Y') }}{{ $order->expected_date ? ' &middot; expected '.$order->expected_date->format('n/j/Y') : '' }}</p>
                        </td>
                        <td class="px-5 py-3 text-gray-700">{{ $order->supplier?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-gray-700">{{ $order->itemCount() }}</td>
                        <td class="px-5 py-3">
                            <p class="font-semibold text-gray-900">₱{{ number_format((float) $order->total, 2) }}</p>
                            @if ($order->isPartial())
                                <p class="text-xs text-amber-600">₱{{ number_format($order->receivedTotal(), 2) }} received</p>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if ($order->isOrdered())
                                <span class="inline-block rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">Ordered</span>
                            @elseif ($order->isPartial())
                                <span class="inline-block rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">Partial</span>
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
                                    @if ($order->received_note)
                                        <p><span class="text-gray-400">Delivery note:</span> {{ $order->received_note }}</p>
                                    @endif
                                    @if ($order->parentOrder)
                                        <p>
                                            <span class="text-gray-400">Balance of:</span>
                                            <span class="font-medium text-gray-900">PO #{{ $order->parentOrder->id }}</span>
                                        </p>
                                    @endif
                                    @if ($order->balanceOrders->isNotEmpty())
                                        <p>
                                            <span class="text-gray-400">Balance raised as:</span>
                                            @foreach ($order->balanceOrders as $balance)
                                                <span class="font-medium text-gray-900">PO #{{ $balance->id }}</span>@if (! $loop->last), @endif
                                            @endforeach
                                        </p>
                                    @endif
                                </div>

                                <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200">
                                    <table class="w-full min-w-[26rem] text-sm">
                                        <thead>
                                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                                <th class="px-4 py-2">Product</th>
                                                <th class="px-4 py-2">Qty</th>
                                                @if ($order->isReceived())
                                                    <th class="px-4 py-2 text-right">Received</th>
                                                @endif
                                                <th class="px-4 py-2 text-right">Unit cost</th>
                                                <th class="px-4 py-2 text-right">Line total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($order->items as $item)
                                                <tr>
                                                    <td class="px-4 py-2 font-medium text-gray-900">{{ $item->product->name }}</td>
                                                    <td class="px-4 py-2 text-gray-700">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} {{ $item->unit?->abbreviation ?? $item->product?->unit }}</td>
                                                    @if ($order->isReceived())
                                                        <td class="px-4 py-2 text-right {{ $item->shortfall() > 0 ? 'font-medium text-amber-600' : 'text-gray-700' }}">
                                                            {{ rtrim(rtrim(number_format($item->receivedQuantity(), 2), '0'), '.') }}
                                                        </td>
                                                    @endif
                                                    <td class="px-4 py-2 text-right text-gray-700">₱{{ number_format((float) $item->unit_cost, 2) }}</td>
                                                    <td class="px-4 py-2 text-right font-semibold text-gray-900">₱{{ number_format((float) $item->line_total, 2) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-t border-gray-200 bg-gray-50">
                                                <td colspan="{{ $order->isReceived() ? 4 : 3 }}" class="px-4 py-2 text-right text-sm font-medium text-gray-700">Total</td>
                                                <td class="px-4 py-2 text-right font-bold text-gray-900">₱{{ number_format((float) $order->total, 2) }}</td>
                                            </tr>
                                            @if ($order->isReceived())
                                                <tr class="border-t border-gray-200 bg-gray-50">
                                                    <td colspan="{{ $order->isReceived() ? 4 : 3 }}" class="px-4 py-2 text-right text-sm font-medium text-gray-700">Received value</td>
                                                    <td class="px-4 py-2 text-right font-bold {{ $order->isPartial() ? 'text-amber-600' : 'text-gray-900' }}">₱{{ number_format($order->receivedTotal(), 2) }}</td>
                                                </tr>
                                            @endif
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
                                <x-modal title="Receive PO #{{ $order->id }}" state="receive" max-width="max-w-2xl">
                                    <p class="text-sm text-gray-600">
                                        Confirm what actually arrived from
                                        <span class="font-medium text-gray-900">{{ $order->supplier?->name ?? 'an unspecified supplier' }}</span>.
                                        Adjust a quantity when the delivery was short, or tick
                                        <span class="font-medium text-gray-900">Not delivered</span> for a line that did not arrive.
                                    </p>

                                    <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200">
                                        <table class="w-full min-w-[34rem] text-sm">
                                            <thead>
                                                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                                                    <th class="px-4 py-2">Product</th>
                                                    <th class="px-4 py-2 text-right">Ordered</th>
                                                    <th class="px-4 py-2">Received</th>
                                                    <th class="px-4 py-2 text-right">Line total</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                @foreach ($order->items as $item)
                                                    <tr>
                                                        <td class="px-4 py-2 font-medium text-gray-900">{{ $item->product->name }}</td>
                                                        <td class="px-4 py-2 text-right text-gray-700">
                                                            {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} {{ $item->unit?->abbreviation ?? $item->product?->unit }}
                                                        </td>
                                                        <td class="px-4 py-2">
                                                            <div class="flex items-center gap-2">
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    step="0.01"
                                                                    form="po-receive-form-{{ $order->id }}"
                                                                    name="received[{{ $item->id }}]"
                                                                    x-model.number="received[{{ $item->id }}]"
                                                                    class="w-24 rounded-lg border border-gray-300 px-2.5 py-1.5 text-right text-sm focus:border-gray-400 focus:outline-none"
                                                                >
                                                                <label class="flex items-center gap-1.5 whitespace-nowrap text-xs text-gray-500">
                                                                    <input
                                                                        type="checkbox"
                                                                        :checked="missing[{{ $item->id }}] === true"
                                                                        @change="toggleMissing({{ $item->id }})"
                                                                        class="rounded border-gray-300 text-gray-900 focus:ring-gray-400"
                                                                    >
                                                                    Not delivered
                                                                </label>
                                                            </div>
                                                        </td>
                                                        <td class="px-4 py-2 text-right font-medium text-gray-900" x-text="lineTotal({{ $item->id }})"></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                                        <p>
                                            Receiving
                                            <span class="font-medium text-gray-900" x-text="receivedUnits()"></span>
                                            of <span class="font-medium text-gray-900" x-text="orderedUnits()"></span> units
                                            <span x-show="shortLines() > 0" x-cloak>
                                                &middot; <span class="font-medium text-amber-600" x-text="shortLines()"></span>
                                                <span x-show="shortLines() === 1">line</span><span x-show="shortLines() !== 1">lines</span> short
                                            </span>
                                        </p>
                                        <button type="button" @click="receiveAll()" class="font-medium text-blue-600 hover:underline">Receive all</button>
                                    </div>

                                    <div class="mt-4">
                                        <label for="po-receive-note-{{ $order->id }}" class="block text-sm font-medium text-gray-700">Delivery note (optional)</label>
                                        <textarea
                                            id="po-receive-note-{{ $order->id }}"
                                            form="po-receive-form-{{ $order->id }}"
                                            name="note"
                                            rows="2"
                                            maxlength="1000"
                                            placeholder="e.g. 40 of 50 delivered, balance to follow"
                                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                                        ></textarea>
                                    </div>

                                    <form id="po-receive-form-{{ $order->id }}" method="POST" action="{{ route('procurement.receive', $order) }}">
                                        @csrf
                                    </form>

                                    <x-slot:footer>
                                        <button type="submit" form="po-receive-form-{{ $order->id }}" class="w-full rounded-lg bg-emerald-700 py-2.5 text-sm font-medium text-white hover:bg-emerald-800">
                                            Receive (<span x-text="'₱' + receivedValue()"></span>)
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
                applyDraft(rows) {
                    this.rows = (rows || []).map((row) => ({
                        product_id: row.product_id != null ? String(row.product_id) : '',
                        quantity: row.quantity ?? '',
                        unit_id: row.unit_id != null ? String(row.unit_id) : '',
                        unit_cost: row.unit_cost ?? '',
                    }));
                },
            };
        }
        window.orderForm = orderForm;

        /**
         * The low-stock panel. Selecting suggestions hands them to the new
         * order modal as a draft, which the modal picks up from the
         * `procurement-draft` window event.
         *
         * Only the first few suggestions sit on the page itself; the full
         * list opens in a scrollable dialog.
         */
        function suggestionPanel(rows = [], limit = 5) {
            return {
                rows: rows.map((row) => ({ ...row, product_id: Number(row.product_id), selected: false })),
                limit: Number(limit) || 5,
                showAll: false,
                get orderableRows() {
                    return this.rows.filter((row) => row.orderable);
                },
                get previewRows() {
                    return this.orderableRows.slice(0, this.limit);
                },
                get selectedRows() {
                    return this.orderableRows.filter((row) => row.selected);
                },
                get selectedCount() {
                    return this.selectedRows.length;
                },
                get allSelected() {
                    return this.previewRows.length > 0 && this.previewRows.every((row) => row.selected);
                },
                get everySelected() {
                    return this.orderableRows.length > 0 && this.orderableRows.every((row) => row.selected);
                },
                toggleAll() {
                    const target = !this.allSelected;
                    this.previewRows.forEach((row) => { row.selected = target; });
                },
                toggleEvery() {
                    const target = !this.everySelected;
                    this.orderableRows.forEach((row) => { row.selected = target; });
                },
                draftRow(row) {
                    return {
                        product_id: String(row.product_id),
                        quantity: row.quantity,
                        unit_id: row.unit_id != null ? String(row.unit_id) : '',
                        unit_cost: row.unit_cost != null ? row.unit_cost : '',
                    };
                },
                draft() {
                    this.showAll = false;
                    window.dispatchEvent(new CustomEvent('procurement-draft', {
                        detail: this.selectedRows.map((row) => this.draftRow(row)),
                    }));
                },
                addOne(row) {
                    this.showAll = false;
                    window.dispatchEvent(new CustomEvent('procurement-draft', {
                        detail: [this.draftRow(row)],
                    }));
                },
            };
        }
        window.suggestionPanel = suggestionPanel;

        /**
         * The receive dialog. A delivery can be short, so every line starts at
         * the ordered quantity and the cashier adjusts what actually arrived.
         */
        function receiveForm(lines = []) {
            const normalised = lines.map((line) => ({
                ...line,
                id: Number(line.id),
                quantity: Number(line.quantity) || 0,
                unitCost: Number(line.unitCost) || 0,
            }));

            return {
                lines: normalised,
                received: Object.fromEntries(normalised.map((line) => [line.id, line.quantity])),
                missing: {},
                lineFor(id) {
                    return this.lines.find((line) => line.id === Number(id)) || null;
                },
                lineTotal(id) {
                    const line = this.lineFor(id);
                    if (!line) return '0.00';
                    return ((Number(this.received[id]) || 0) * line.unitCost).toFixed(2);
                },
                orderedUnits() {
                    return this.lines.reduce((sum, line) => sum + line.quantity, 0);
                },
                receivedUnits() {
                    return this.lines.reduce((sum, line) => sum + (Number(this.received[line.id]) || 0), 0);
                },
                receivedValue() {
                    return this.lines
                        .reduce((sum, line) => sum + (Number(this.received[line.id]) || 0) * line.unitCost, 0)
                        .toFixed(2);
                },
                shortLines() {
                    return this.lines.filter((line) => (Number(this.received[line.id]) || 0) < line.quantity).length;
                },
                toggleMissing(id) {
                    const line = this.lineFor(id);
                    if (!line) return;
                    this.missing[id] = !this.missing[id];
                    this.received[id] = this.missing[id] ? 0 : line.quantity;
                },
                receiveAll() {
                    this.lines.forEach((line) => {
                        this.received[line.id] = line.quantity;
                        this.missing[line.id] = false;
                    });
                },
            };
        }
        window.receiveForm = receiveForm;
    </script>
@endpush