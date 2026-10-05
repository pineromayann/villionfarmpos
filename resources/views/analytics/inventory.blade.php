@extends('layouts.app')

@section('title', 'Inventory analytics')
@section('heading', 'Inventory analytics')
@section('subheading', 'Stock position, valuation and movement for the selected period.')

@section('content')
    <div class="mx-auto max-w-[120rem] space-y-4 sm:space-y-6">
        @include('analytics.partials.filters', ['visible' => ['category', 'product'], 'exportRoute' => 'analytics.inventory.csv'])

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Stock value at cost',
                'value' => \App\Support\Money::peso($report['summary']['stock_value_cost']),
                'hint' => number_format($report['summary']['products']).' products tracked',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Retail value',
                'value' => \App\Support\Money::peso($report['summary']['stock_value_retail']),
                'hint' => \App\Support\Money::percent($report['summary']['potential_margin']).' potential margin',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Units on hand',
                'value' => \App\Support\Money::units($report['summary']['stock_units']),
                'hint' => number_format($report['summary']['stocked_products']).' lines carrying stock',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Needs attention',
                'value' => number_format($report['summary']['low_stock'] + $report['summary']['expired']),
                'hint' => number_format($report['summary']['low_stock']).' low, '
                    .number_format($report['summary']['expired']).' expired',
            ])
        </div>

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Out of stock',
                'value' => number_format($report['summary']['out_of_stock']),
                'hint' => 'Nothing sellable on hand',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Expiring within 6 months',
                'value' => number_format($report['summary']['expiring_soon']),
                'hint' => 'Plan clearance pricing',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Stock in (period)',
                'value' => \App\Support\Money::units($report['movementTypes'][0]['value']),
                'hint' => 'Units received',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Stock out (period)',
                'value' => \App\Support\Money::units($report['movementTypes'][1]['value']),
                'hint' => 'Units issued',
            ])
        </div>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card title="Value by category">
                @include('analytics.partials.donut-chart', ['data' => array_map(
                    fn (array $row) => ['label' => $row['label'], 'value' => $row['cost_value']],
                    $report['valuation'],
                )])
            </x-analytics.card>

            <x-analytics.card title="Expiry profile">
                @include('analytics.partials.donut-chart', ['data' => array_map(
                    fn (array $row) => ['label' => $row['label'], 'value' => $row['value']],
                    $report['expiry'],
                )])
            </x-analytics.card>
        </div>

        <x-analytics.card title="Movement activity"
                          :subtitle="'Net movement per '.$filters->period->grouping().': stock in less stock out.'">
            @include('analytics.partials.bar-chart', ['data' => array_map(
                fn (array $row) => ['label' => $row['label'], 'value' => $row['in'] - $row['out']],
                $report['activity'],
            )])
        </x-analytics.card>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card flush title="Movement by reason" subtitle="Where stock actually went, including shrinkage.">
                <x-analytics.table :is-empty="$report['movementReasons'] === []" empty="No stock movements in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Reason</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Movements</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Units</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['movementReasons'] as $row)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $row['label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ number_format($row['movements']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($row['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>

            <x-analytics.card flush title="Slow moving stock" subtitle="Held, unsold in this period, at cost value.">
                <x-analytics.table :is-empty="$report['slowMoving'] === []"
                                   empty="Every product with stock on hand sold in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Product</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Units</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Value at cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['slowMoving'] as $row)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-gray-900">{{ $row['name'] }}</span>
                                    <span class="block text-xs text-gray-400">
                                        {{ ucfirst($row['category']) }}
                                        @if ($row['last_sold'])
                                            · last sold {{ \Illuminate\Support\Carbon::parse($row['last_sold'])->format('j M Y') }}
                                        @else
                                            · no recorded sales
                                        @endif
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($row['units']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-gray-900">{{ \App\Support\Money::peso($row['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>
        </div>

        <x-analytics.card flush title="Largest movements">
            <x-analytics.table :is-empty="$report['topMovements'] === []" empty="No stock movements in this period.">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 text-left font-medium">Product</th>
                        <th scope="col" class="px-4 py-2.5 text-left font-medium">Reason</th>
                        <th scope="col" class="hidden px-4 py-2.5 text-left font-medium lg:table-cell">Supplier</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Quantity</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($report['topMovements'] as $row)
                        <tr class="hover:bg-gray-50/70">
                            <td class="px-4 py-2.5 font-medium text-gray-900">{{ $row['product'] }}</td>
                            <td class="px-4 py-2.5">
                                <span @class([
                                    'inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-emerald-50 text-emerald-700' => $row['type'] === 'in',
                                    'bg-gray-100 text-gray-600' => $row['type'] !== 'in',
                                ])>{{ $row['type'] === 'in' ? 'In' : 'Out' }} · {{ $row['reason'] }}</span>
                            </td>
                            <td class="hidden px-4 py-2.5 text-gray-500 lg:table-cell">{{ $row['supplier'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($row['quantity']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-500">
                                {{ \Illuminate\Support\Carbon::parse($row['date'])->format('j M Y') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-analytics.table>
        </x-analytics.card>

        <p class="text-xs text-gray-400">
            Valuation is current stock at its current cost. Turnover is not reported: stock movements record no
            opening or closing balance, so any turnover figure would rest on an assumed opening stock rather than
            recorded data.
        </p>
    </div>
@endsection
