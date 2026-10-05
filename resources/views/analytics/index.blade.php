@extends('layouts.app')

@section('title', 'Analytics overview')
@section('heading', 'Analytics overview')
@section('subheading', $filters->period->label().' — trading, stock and obligations at a glance.')

@section('content')
    <div class="mx-auto max-w-[120rem] space-y-4 sm:space-y-6">
        @include('analytics.partials.filters', ['visible' => ['category']])

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Net sales',
                'value' => \App\Support\Money::peso($summary['net_sales']),
                'change' => $report['comparison']['net_sales_change'] ?? null,
            ])
            @include('analytics.partials.stat', [
                'label' => 'Gross profit',
                'value' => \App\Support\Money::peso($profit['gross_profit']),
                'hint' => \App\Support\Money::percent($profit['margin']).' margin',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Transactions',
                'value' => number_format($summary['transactions']),
                'hint' => \App\Support\Money::peso($summary['average_transaction']).' average basket',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Units sold',
                'value' => \App\Support\Money::units($summary['net_units_sold']),
                'hint' => $summary['returns'] > 0
                    ? \App\Support\Money::peso($summary['returns']).' returned'
                    : 'No returns in period',
            ])
        </div>

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Stock value at cost',
                'value' => \App\Support\Money::peso($inventory['stock_value_cost']),
                'hint' => number_format($inventory['products']).' products on hand',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Low stock',
                'value' => number_format($inventory['low_stock']),
                'hint' => number_format($inventory['out_of_stock']).' out of stock',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Owed to partners',
                'value' => \App\Support\Money::peso($consignment['outstanding']),
                'hint' => \App\Support\Money::peso($consignment['commission']).' commission earned',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Purchase orders open',
                'value' => number_format($procurement['awaiting_orders']),
                'hint' => \App\Support\Money::peso($procurement['awaiting_value']).' committed',
            ])
        </div>

        @if ($profit['uncosted_products'] > 0)
            <x-analytics.notice>
                {{ $profit['uncosted_products'] }} product line(s) have no recorded cost and are left out of gross profit
                rather than being valued at zero. Set a cost price, or receive stock with a recorded cost, to include them.
            </x-analytics.notice>
        @endif

        <div class="grid gap-4 lg:grid-cols-3 lg:gap-6">
            <x-analytics.card class="lg:col-span-2" title="Net sales trend">
                <x-slot:actions>
                    <a href="{{ route('analytics.sales', $filters->query()) }}"
                       class="text-xs font-medium text-emerald-700 hover:underline">
                        Sales report
                    </a>
                </x-slot:actions>

                @include('analytics.partials.bar-chart', ['data' => $trend])
            </x-analytics.card>

            <x-analytics.card title="Sales by category">
                @include('analytics.partials.donut-chart', ['data' => $byCategory])
            </x-analytics.card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card flush title="Top products">
                <x-analytics.table :is-empty="$topProducts === []" empty="No products sold in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Product</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Units</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($topProducts as $product)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-gray-900">{{ $product['name'] }}</span>
                                    <span class="block text-xs text-gray-400">{{ ucfirst($product['category']) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($product['units']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-gray-900">{{ \App\Support\Money::peso($product['revenue']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>

            <x-analytics.card flush title="Top customers">
                <x-analytics.table :is-empty="$topCustomers === []" empty="No customer sales in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Customer</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Sales</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Spend</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($topCustomers as $customer)
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
