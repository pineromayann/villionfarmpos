@extends('layouts.app')

@section('title', 'Sales analytics')
@section('heading', 'Sales analytics')
@section('subheading', $filters->period->label().' — owned-stock trading, net of returns.')

@section('content')
    <div class="mx-auto max-w-[120rem] space-y-4 sm:space-y-6">
        @include('analytics.partials.filters', ['visible' => ['category', 'product', 'customer', 'payment_method'], 'exportRoute' => 'analytics.sales.csv'])

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Net sales',
                'value' => \App\Support\Money::peso($report['summary']['net_sales']),
                'change' => $report['comparison']['net_sales_change'],
            ])
            @include('analytics.partials.stat', [
                'label' => 'Gross takings',
                'value' => \App\Support\Money::peso($report['summary']['gross_sales']),
                'hint' => \App\Support\Money::peso($report['summary']['discounts']).' discounts given',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Returns',
                'value' => \App\Support\Money::peso($report['summary']['returns']),
                'hint' => \App\Support\Money::units($report['summary']['return_units']).' units back',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Transactions',
                'value' => number_format($report['summary']['transactions']),
                'change' => $report['comparison']['transactions_change'],
            ])
        </div>

        @if ($report['summary']['has_consigned_lines'])
            <x-analytics.notice tone="sky">
                Some sales in this period carried consigned lines. Gross takings come from the sale header and
                include them; net sales, every breakdown, the trend and the product tables count owned stock only,
                so the two figures differ by the consigned value on purpose.
            </x-analytics.notice>
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            @include('analytics.partials.stat', [
                'label' => 'Units sold',
                'value' => \App\Support\Money::units($report['summary']['net_units_sold']),
                'change' => $report['comparison']['units_change'],
            ])
            @include('analytics.partials.stat', [
                'label' => 'Average basket',
                'value' => \App\Support\Money::peso($report['summary']['average_transaction']),
                'hint' => 'Before returns',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Average unit price',
                'value' => \App\Support\Money::peso($report['summary']['average_unit_price']),
                'hint' => \App\Support\Money::percent($report['summary']['return_rate']).' return rate',
            ])
        </div>

        <p class="text-xs text-gray-400">
            Owned figures are built from sale lines, so a discount applied at the till applies to the header but not
            to any individual line. Net sales can therefore sit above or below gross takings less discounts when a
            discount was given; the consignment page owns that money and the margin page values it.
        </p>

        <x-analytics.card title="Revenue trend">
            @include('analytics.partials.bar-chart', ['data' => $report['trend']])
        </x-analytics.card>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card title="Revenue by day of week">
                @include('analytics.partials.bar-chart', ['data' => $report['byDay']])
            </x-analytics.card>

            <x-analytics.card title="Revenue by hour">
                @include('analytics.partials.bar-chart', ['data' => $report['byHour']])
            </x-analytics.card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card title="By payment method">
                @include('analytics.partials.donut-chart', ['data' => $report['byPayment']])
            </x-analytics.card>

            <x-analytics.card title="By category">
                @include('analytics.partials.donut-chart', ['data' => $report['byCategory']])
            </x-analytics.card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card flush title="Best selling products" subtitle="Owned stock only, net of customer returns.">
                <x-analytics.table :is-empty="$report['byProduct'] === []" empty="No products sold in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Product</th>
                            <th scope="col" class="hidden px-4 py-2.5 text-left font-medium sm:table-cell">Category</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Units</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Net revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['byProduct'] as $product)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $product['name'] }}</td>
                                <td class="hidden px-4 py-2.5 text-gray-500 sm:table-cell">{{ ucfirst($product['category']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($product['units']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-gray-900">{{ \App\Support\Money::peso($product['revenue']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>

            <x-analytics.card flush title="Top customers">
                <x-analytics.table :is-empty="$report['byCustomer'] === []" empty="No customer sales in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Customer</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Transactions</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Net spend</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['byCustomer'] as $customer)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5 font-medium text-gray-900">
                                    {{ $customer['name'] }}
                                    @if ($customer['is_walk_in'])
                                        <span class="ml-1 text-xs font-normal text-gray-400">walk-in</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ number_format($customer['transactions']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-gray-900">{{ \App\Support\Money::peso($customer['spend']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>
        </div>
    </div>
@endsection
