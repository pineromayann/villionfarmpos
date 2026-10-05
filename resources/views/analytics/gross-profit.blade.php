@extends('layouts.app')

@section('title', 'Gross profit')
@section('heading', 'Gross profit')
@section('subheading', $filters->period->label().' — what the shop keeps from trading owned stock.')

@section('content')
    <div class="mx-auto max-w-[120rem] space-y-4 sm:space-y-6">
        @include('analytics.partials.filters', ['visible' => ['category', 'product'], 'exportRoute' => 'analytics.gross-profit.csv'])

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Gross profit',
                'value' => \App\Support\Money::peso($report['summary']['gross_profit']),
                'change' => $report['comparison']['gross_profit_change'],
            ])
            @include('analytics.partials.stat', [
                'label' => 'Gross margin',
                'value' => \App\Support\Money::percent($report['summary']['margin']),
                'hint' => \App\Support\Money::percent($report['summary']['markup']).' markup on cost',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Revenue',
                'value' => \App\Support\Money::peso($report['summary']['revenue']),
                'hint' => 'Net of '.\App\Support\Money::peso($report['summary']['returns']).' returns',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Cost of goods',
                'value' => \App\Support\Money::peso($report['summary']['cogs']),
                'hint' => 'Recorded cost of stock sold',
            ])
        </div>

        @if ($report['summary']['uncosted_products'] > 0)
            <x-analytics.notice :title="$report['summary']['uncosted_products'].' product(s) sold in this period have no recorded cost.'">
                Their revenue — {{ \App\Support\Money::peso($report['summary']['excluded_revenue']) }} — is
                excluded from the totals above, along with any cost, because a figure cannot be split reliably
                without one. Set a cost price on the product, or receive stock with a recorded cost, to include them.
            </x-analytics.notice>
        @endif

        <x-analytics.card title="Revenue and gross profit trend">
            @include('analytics.partials.bar-chart', ['data' => array_map(
                fn (array $row) => ['label' => $row['label'], 'value' => $row['revenue']],
                $report['trend'],
            )])
        </x-analytics.card>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card title="Profit by category">
                @include('analytics.partials.donut-chart', ['data' => array_map(
                    fn (array $row) => ['label' => $row['label'], 'value' => $row['gross_profit']],
                    $report['byCategory'],
                )])
            </x-analytics.card>

            <x-analytics.card flush title="Most profitable products" subtitle="By gross profit, not by revenue.">
                <x-analytics.table :is-empty="$report['byProduct'] === []" empty="No costed products sold in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Product</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Revenue</th>
                            <th scope="col" class="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Cost</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Profit</th>
                            <th scope="col" class="hidden px-4 py-2.5 text-right font-medium md:table-cell">Margin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['byProduct'] as $row)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $row['label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::peso($row['value']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden sm:table-cell">{{ \App\Support\Money::peso($row['cost']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-emerald-700">{{ \App\Support\Money::peso($row['gross_profit']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden md:table-cell">{{ \App\Support\Money::percent($row['margin']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>
        </div>

        <x-analytics.card flush
                         title="Selling price against cost"
                         subtitle="Weakest margins first — a negative or thin margin here is priced under water.">
            <x-analytics.table :is-empty="$report['pricePoints'] === []" empty="No costed products sold in this period.">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 text-left font-medium">Product</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Retail price</th>
                        <th scope="col" class="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Cost</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Margin at list</th>
                        <th scope="col" class="hidden px-4 py-2.5 text-right font-medium md:table-cell">Units sold</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($report['pricePoints'] as $row)
                        <tr class="hover:bg-gray-50/70">
                            <td class="px-4 py-2.5 font-medium text-gray-900">{{ $row['name'] }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::peso($row['price']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden sm:table-cell">
                                {{ $row['cost'] === null ? '—' : \App\Support\Money::peso($row['cost']) }}
                            </td>
                            <td @class([
                                'whitespace-nowrap px-4 py-2.5 text-right font-medium',
                                'text-red-600' => ($row['margin'] ?? 100) < 10,
                                'text-gray-600' => ($row['margin'] ?? 100) >= 10,
                            ])>
                                {{ $row['margin'] === null ? '—' : \App\Support\Money::percent($row['margin']) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden md:table-cell">{{ \App\Support\Money::units($row['sold']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-analytics.table>
        </x-analytics.card>

        <p class="text-xs text-gray-400">
            Cost of goods uses the cost recorded against stock received where available, and the product's current cost
            price only where no costing history exists. Consigned goods are excluded here and reported on the
            consignment page as commission.
        </p>
    </div>
@endsection
