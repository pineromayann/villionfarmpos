@extends('reports.pdf.layout')

@section('title', 'Sales Report')

@section('meta')
    Generated {{ $generatedAt->format('n/j/Y, g:i A') }}
    @if ($range['from'] || $range['to'])
        &middot; Period: {{ $range['from']?->format('n/j/Y') ?? 'Start' }} &ndash; {{ $range['to']?->format('n/j/Y') ?? 'Now' }}
    @else
        &middot; Period: All time
    @endif
@endsection

@section('body')
    <table class="summary">
        <tr>
            <td class="label">Total revenue</td>
            <td class="value">₱{{ number_format($totalRevenue, 2) }}</td>
            <td class="label">Items sold</td>
            <td class="value">{{ (int) $itemsSold }}</td>
            <td class="label">Transactions</td>
            <td class="value">{{ $sales->count() }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Payment</th>
                <th class="text-right">Subtotal</th>
                <th class="text-right">Discount</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sales as $sale)
                <tr>
                    <td>{{ $sale->created_at->format('n/j/Y, g:i A') }}</td>
                    <td>{{ $sale->customer?->name ?? 'Walk-in' }}</td>
                    <td>{{ $sale->itemCount() }}</td>
                    <td>{{ \Illuminate\Support\Str::of($sale->payment_method)->replace('_', ' ')->title() }}</td>
                    <td class="text-right">₱{{ number_format($sale->subtotal, 2) }}</td>
                    <td class="text-right">₱{{ number_format($sale->discount, 2) }}</td>
                    <td class="text-right">₱{{ number_format($sale->total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No sales recorded for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h1 style="margin-top: 20px;">Top products</h1>
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th class="text-right">Units sold</th>
                <th class="text-right">Revenue</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($topProducts as $product)
                <tr>
                    <td>{{ $product['name'] }}</td>
                    <td class="text-right">{{ (int) $product['units_sold'] }}</td>
                    <td class="text-right">₱{{ number_format($product['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">No sales recorded for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
