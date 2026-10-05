@extends('layouts.app')

@section('title', 'Procurement analytics')
@section('heading', 'Procurement analytics')
@section('subheading', $filters->period->label().' — what was ordered, what arrived, and from whom.')

@section('content')
    <div class="mx-auto max-w-[120rem] space-y-4 sm:space-y-6">
        @include('analytics.partials.filters', ['visible' => ['supplier', 'status'], 'exportRoute' => 'analytics.procurement.csv'])

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Received value',
                'value' => \App\Support\Money::peso($report['summary']['received_value']),
                'hint' => number_format($report['summary']['received_orders']).' orders received',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Ordered value',
                'value' => \App\Support\Money::peso($report['summary']['ordered_value']),
                'hint' => number_format($report['summary']['orders']).' orders placed',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Awaiting delivery',
                'value' => \App\Support\Money::peso($report['summary']['awaiting_value']),
                'hint' => number_format($report['summary']['awaiting_orders']).' open orders',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Cancelled',
                'value' => \App\Support\Money::peso($report['summary']['cancelled_value']),
                'hint' => number_format($report['summary']['cancelled_orders']).' orders cancelled',
            ])
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            @include('analytics.partials.stat', [
                'label' => 'Average order value',
                'value' => \App\Support\Money::peso($report['summary']['average_order']),
                'hint' => 'Across orders in period',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Suppliers used',
                'value' => number_format($report['summary']['suppliers_used']),
                'hint' => 'Distinct suppliers ordered from',
            ])
        </div>

<x-analytics.card title="Received value"
                          subtitle="Only a receipt moves stock and creates a cost, so received value is the figure that ties back to inventory and gross profit. An order on the books is money committed, not money spent.">
            @include('analytics.partials.bar-chart', ['data' => $report['trend'], 'valueKey' => 'received'])
        </x-analytics.card>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card title="Spend by supplier">
                @include('analytics.partials.donut-chart', ['data' => array_map(
                    fn (array $row) => ['label' => $row['name'], 'value' => $row['value']],
                    $report['bySupplier'],
                )])
            </x-analytics.card>

            <x-analytics.card title="Orders by status">
                @include('analytics.partials.donut-chart', ['data' => array_map(
                    fn (array $row) => ['label' => $row['label'], 'value' => $row['value']],
                    $report['byStatus'],
                )])
            </x-analytics.card>
        </div>

        <x-analytics.card flush
                         title="Supplier performance"
                         subtitle="Received orders only, with delivery lead time from order to receipt.">
            <x-analytics.table :is-empty="$report['bySupplier'] === []" empty="No received orders in this period.">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 text-left font-medium">Supplier</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Orders</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Received value</th>
                        <th scope="col" class="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Average order</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($report['bySupplier'] as $supplier)
                        <tr class="hover:bg-gray-50/70">
                            <td class="px-4 py-2.5 font-medium text-gray-900">{{ $supplier['name'] }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ number_format($supplier['orders']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-gray-900">{{ \App\Support\Money::peso($supplier['value']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden sm:table-cell">{{ \App\Support\Money::peso($supplier['average']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-analytics.table>
        </x-analytics.card>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card flush title="Most purchased products">
                <x-analytics.table :is-empty="$report['byProduct'] === []" empty="No received items in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Product</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Quantity</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['byProduct'] as $row)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $row['name'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($row['quantity']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-gray-900">{{ \App\Support\Money::peso($row['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>

            <x-analytics.card flush title="Delivery lead time" subtitle="Days from order to receipt, fastest first.">
                <x-analytics.table :is-empty="$report['leadTimes'] === []" empty="No received orders in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Supplier</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Orders</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Average days</th>
                            <th scope="col" class="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Longest</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['leadTimes'] as $row)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $row['name'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ number_format($row['orders']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ $row['average_days'] ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden sm:table-cell">{{ $row['latest_days'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>
        </div>

        <x-analytics.card flush
                         title="Awaiting delivery"
                         subtitle="Live right now, not limited to the reporting period.">
            <x-analytics.table :is-empty="$report['openOrders']->isEmpty()" empty="Every purchase order has been received.">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 text-left font-medium">Supplier</th>
                        <th scope="col" class="hidden px-4 py-2.5 text-left font-medium sm:table-cell">Ordered</th>
                        <th scope="col" class="px-4 py-2.5 text-left font-medium">Expected</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Days open</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($report['openOrders'] as $order)
                        <tr class="hover:bg-gray-50/70">
                            <td class="px-4 py-2.5 font-medium text-gray-900">{{ $order['supplier'] }}</td>
                            <td class="hidden whitespace-nowrap px-4 py-2.5 text-gray-500 sm:table-cell">
                                {{ \Illuminate\Support\Carbon::parse($order['order_date'])->format('j M Y') }}
                            </td>
                            <td @class(['whitespace-nowrap px-4 py-2.5', 'font-medium text-red-600' => $order['is_overdue'], 'text-gray-500' => ! $order['is_overdue']])>
                                {{ $order['expected_date']
                                    ? \Illuminate\Support\Carbon::parse($order['expected_date'])->format('j M Y')
                                    : '—' }}
                                @if ($order['is_overdue'])
                                    <span class="block text-xs">overdue</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ number_format($order['days_open']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-gray-900">{{ \App\Support\Money::peso($order['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-analytics.table>
        </x-analytics.card>
    </div>
@endsection
