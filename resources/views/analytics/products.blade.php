@extends('layouts.app')

@section('title', 'Product performance')
@section('heading', 'Product performance')
@section('subheading', $filters->period->label().' — which lines carry the business and which tie up cash.')

@section('content')
    <div class="mx-auto max-w-[120rem] space-y-4 sm:space-y-6">
        @include('analytics.partials.filters', ['visible' => ['category', 'product'], 'exportRoute' => 'analytics.products.csv'])

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-5">
            @include('analytics.partials.stat', [
                'label' => 'Revenue',
                'value' => \App\Support\Money::peso($report['summary']['revenue']),
                'hint' => number_format($report['summary']['products']).' product lines',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Gross profit',
                'value' => \App\Support\Money::peso($report['summary']['gross_profit']),
                'hint' => \App\Support\Money::percent($report['summary']['margin']).' margin',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Products sold',
                'value' => number_format($report['summary']['sold_products']),
                'hint' => 'of '.number_format($report['summary']['products']).' lines',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Dead stock value',
                'value' => \App\Support\Money::peso($report['summary']['dead_stock_value']),
                'hint' => number_format($report['summary']['unsold_products']).' products unsold, at cost',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Average units per selling line',
                'value' => \App\Support\Money::units($report['summary']['average_units']),
                'hint' => 'Across products that sold',
            ])
        </div>

        @if ($report['summary']['uncosted_products'] > 0)
            <x-analytics.notice>
                {{ $report['summary']['uncosted_products'] }} product line(s) have no recorded cost —
                {{ \App\Support\Money::peso($report['summary']['excluded_revenue']) }} of revenue is shown in the
                breakdown but left out of the revenue and margin totals above, since profit cannot be split without
                a cost. Cost shown is a lifetime average of recorded receipts, not FIFO.
            </x-analytics.notice>
        @endif

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card title="Revenue by category">
                @include('analytics.partials.donut-chart', ['data' => array_map(
                    fn (array $row) => ['label' => $row['label'], 'value' => $row['revenue']],
                    $report['byCategory'],
                )])
            </x-analytics.card>

            <x-analytics.card title="Units sold against stock on hand"
                              subtitle="High sold with low stock means reorder; low sold with high stock ties up cash.">
                @include('analytics.partials.bar-chart', ['data' => array_map(
                    fn (array $row) => ['label' => \Illuminate\Support\Str::limit($row['name'], 12), 'value' => $row['units']],
                    $report['matrix'],
                )])
            </x-analytics.card>
        </div>

        <x-analytics.card flush
                         title="Product breakdown"
                         subtitle="Owned stock only, net of customer returns. Products still holding stock are listed even if nothing sold. A dash in the margin column means the cost is unknown.">
            <x-analytics.table :is-empty="$products->isEmpty()"
                               empty="Nothing sold and nothing left on hand for this period.">
                @php
                    // Label, alignment and the breakpoint a column reappears at. The
                    // table scrolls sideways, so the narrowest columns are simply
                    // dropped rather than squeezed.
                    $columns = [
                        'name' => ['Product', 'text-left', ''],
                        'units_sold' => ['Sold', 'text-right', 'hidden sm:table-cell'],
                        'stock_on_hand' => ['On hand', 'text-right', ''],
                        'revenue' => ['Revenue', 'text-right', ''],
                        'cost' => ['Cost', 'text-right', 'hidden xl:table-cell'],
                        'gross_profit' => ['Profit', 'text-right', ''],
                        'margin' => ['Margin', 'text-right', 'hidden lg:table-cell'],
                        'stock_value' => ['Stock value', 'text-right', 'hidden md:table-cell'],
                    ];
                @endphp

                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        @foreach ($columns as $column => [$label, $align, $visibility])
                            @php
                                $isSorted = $sort === $column;
                                $nextDirection = $isSorted && $direction === 'desc' ? 'asc' : 'desc';
                            @endphp
                            <th scope="col" class="whitespace-nowrap px-4 py-2.5 font-medium {{ $align }} {{ $visibility }} {{ $column === 'name' ? 'sticky left-0 z-10 bg-gray-50' : '' }}">
                                <a href="{{ request()->fullUrlWithQuery(['sort' => $column, 'direction' => $nextDirection, 'page' => null]) }}"
                                   class="inline-flex items-center gap-1 hover:text-gray-900 {{ $isSorted ? 'text-gray-900' : '' }}">
                                    {{ $label }}
                                    @if ($isSorted)
                                        <span aria-hidden="true">{{ $direction === 'desc' ? '↓' : '↑' }}</span>
                                    @endif
                                </a>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @foreach ($products as $row)
                        <tr class="group hover:bg-gray-50/70">
                            <td class="sticky left-0 z-10 max-w-[13rem] bg-white px-4 py-2.5 group-hover:bg-gray-50/70 sm:max-w-none">
                                <span class="block break-words font-medium text-gray-900" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                <span class="block text-xs text-gray-400">
                                    {{ ucfirst($row['category']) }}
                                    @if ($row['unit']) · {{ $row['unit'] }} @endif
                                    @if ($row['is_low_stock'])
                                        · <span class="font-medium text-red-500">low stock</span>
                                    @endif
                                    @if ($row['is_expiring'])
                                        · <span class="font-medium text-amber-600">expiring {{ $row['expiry_date'] }}</span>
                                    @endif
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($row['units_sold']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($row['stock_on_hand']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-gray-900">{{ \App\Support\Money::peso($row['revenue']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden xl:table-cell">
                                {{ $row['cost'] === null ? '—' : \App\Support\Money::peso($row['cost']) }}
                                @if ($row['uses_fallback_cost'])
                                    <span class="block text-[10px] text-gray-400" title="No recorded stock-in cost; current cost price used">est.</span>
                                @endif
                            </td>
                            <td @class([
                                'whitespace-nowrap px-4 py-2.5 text-right font-medium',
                                'text-emerald-700' => ($row['gross_profit'] ?? 0) > 0,
                                'text-red-600' => ($row['gross_profit'] ?? 0) < 0,
                                'text-gray-400' => $row['gross_profit'] === null,
                            ])>
                                {{ $row['gross_profit'] === null ? '—' : \App\Support\Money::peso($row['gross_profit']) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden lg:table-cell">
                                {{ $row['margin'] === null ? '—' : \App\Support\Money::percent($row['margin']) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden md:table-cell">{{ \App\Support\Money::peso($row['stock_value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>

                <x-slot:footer>
                    {{ $products->links('pagination::analytics') }}
                </x-slot:footer>
            </x-analytics.table>
        </x-analytics.card>

        <p class="text-xs text-gray-400">
            Cost uses the recorded cost of stock received where it exists. Rows marked "est." have no costing history and
            fall back to the product's current cost price, so their margin is indicative rather than exact.
        </p>
    </div>
@endsection
