@extends('layouts.app')

@section('title', 'Consignment analytics')
@section('heading', 'Consignment analytics')
@section('subheading', $filters->period->label().' — commission earned on goods held for partners.')

@section('content')
    <div class="mx-auto max-w-[120rem] space-y-4 sm:space-y-6">
        @include('analytics.partials.filters', ['visible' => ['partner'], 'exportRoute' => 'analytics.consignment.csv'])

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Commission earned',
                'value' => \App\Support\Money::peso($report['summary']['commission']),
                'hint' => \App\Support\Money::percent($report['summary']['commission_rate']).' of retail',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Retail value sold',
                'value' => \App\Support\Money::peso($report['summary']['retail_value']),
                'hint' => \App\Support\Money::units($report['summary']['sold_units']).' units',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Owed to partners',
                'value' => \App\Support\Money::peso($report['summary']['outstanding']),
                'hint' => 'Net of settlements, across all time',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Received from partners',
                'value' => \App\Support\Money::peso($report['summary']['received_value']),
                'hint' => number_format($report['summary']['received_orders']).' receipts',
            ])
        </div>

        <div class="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
            @include('analytics.partials.stat', [
                'label' => 'Payable on sales',
                'value' => \App\Support\Money::peso($report['summary']['payable']),
                'hint' => 'Net of customer returns',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Written off',
                'value' => \App\Support\Money::peso($report['summary']['written_off_value']),
                'hint' => 'Damage, loss and returns',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Settled',
                'value' => \App\Support\Money::peso($report['summary']['settled']),
                'hint' => 'Paid to partners in period',
            ])
            @include('analytics.partials.stat', [
                'label' => 'Partners',
                'value' => number_format($report['summary']['partners']),
                'hint' => \App\Support\Money::units($report['summary']['received_units']).' units received',
            ])
        </div>

        <x-analytics.card title="Retail and commission trend"
                          :subtitle="'Commission per '.$filters->period->grouping().': retail value less the partner payable.'">
            @include('analytics.partials.bar-chart', ['data' => array_map(
                fn (array $row) => ['label' => $row['label'], 'value' => $row['commission']],
                $report['trend'],
            )])
        </x-analytics.card>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card title="Commission by partner">
                @include('analytics.partials.donut-chart', ['data' => array_map(
                    fn (array $row) => ['label' => $row['name'], 'value' => $row['commission']],
                    array_filter($report['byPartner'], fn (array $row) => $row['retail'] > 0),
                )])
            </x-analytics.card>

            <x-analytics.card title="Adjustments by reason">
                @include('analytics.partials.donut-chart', ['data' => array_map(
                    fn (array $row) => ['label' => $row['label'], 'value' => $row['value']],
                    $report['adjustments'],
                )])
            </x-analytics.card>
        </div>

        <x-analytics.card flush
                         title="Partner performance"
                         subtitle="What each partner's goods sold for, and what is still owed.">
            <x-analytics.table :is-empty="$report['byPartner'] === []" empty="No consignment partners recorded.">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 text-left font-medium">Partner</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Units</th>
                        <th scope="col" class="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Retail</th>
                        <th scope="col" class="hidden px-4 py-2.5 text-right font-medium md:table-cell">Payable</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Commission</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Balance due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($report['byPartner'] as $partner)
                        <tr class="hover:bg-gray-50/70">
                            <td class="px-4 py-2.5 font-medium text-gray-900">{{ $partner['name'] }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($partner['units']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden sm:table-cell">{{ \App\Support\Money::peso($partner['retail']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600 hidden md:table-cell">{{ \App\Support\Money::peso($partner['payable']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-emerald-700">{{ \App\Support\Money::peso($partner['commission']) }}</td>
                            <td @class([
                                'whitespace-nowrap px-4 py-2.5 text-right font-medium',
                                'text-gray-900' => $partner['balance_due'] >= 0,
                                'text-red-600' => $partner['balance_due'] < 0,
                            ])>{{ \App\Support\Money::peso($partner['balance_due']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-analytics.table>
        </x-analytics.card>

        <div class="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <x-analytics.card flush title="Best selling consigned products">
                <x-analytics.table :is-empty="$report['byProduct'] === []" empty="No consigned goods sold in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Product</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Units</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Retail</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Commission</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['byProduct'] as $row)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $row['name'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($row['units']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::peso($row['retail']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-medium text-emerald-700">{{ \App\Support\Money::peso($row['commission']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>

            <x-analytics.card flush title="Write-offs and returns">
                <x-analytics.table :is-empty="$report['adjustments'] === []" empty="No adjustments in this period.">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Reason</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Entries</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Units</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($report['adjustments'] as $row)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $row['label'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ number_format($row['entries']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::units($row['units']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-gray-600">{{ \App\Support\Money::peso($row['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-analytics.table>
            </x-analytics.card>
        </div>

        <x-analytics.card flush
                         title="Outstanding balances"
                         subtitle="Payable on sales plus write-offs, less everything settled.">
            <x-analytics.table :is-empty="$report['balances'] === []" empty="No consignment partners recorded.">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 text-left font-medium">Partner</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Balance due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($report['balances'] as $balance)
                        <tr class="hover:bg-gray-50/70">
                            <td class="px-4 py-2.5 font-medium text-gray-900">{{ $balance['name'] }}</td>
                            <td @class([
                                'whitespace-nowrap px-4 py-2.5 text-right font-medium',
                                'text-gray-900' => $balance['balance_due'] >= 0,
                                'text-red-600' => $balance['balance_due'] < 0,
                            ])>{{ \App\Support\Money::peso($balance['balance_due']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-analytics.table>
        </x-analytics.card>

        <p class="text-xs text-gray-400">
            Consigned goods are excluded from the sales and gross profit reports, which cover owned stock only. Here the
            shop's return is the commission: retail value less the amount owed back to the partner.
        </p>
    </div>
@endsection
