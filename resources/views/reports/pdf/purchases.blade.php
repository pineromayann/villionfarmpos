@extends('reports.pdf.layout')

@section('title', 'Purchases Report')

@section('meta')
    Generated {{ $generatedAt->format('n/j/Y, g:i A') }}
    @if ($range['from'] || $range['to'])
        &middot; Period: {{ $range['from']?->format('n/j/Y') ?? 'Start' }} &ndash; {{ $range['to']?->format('n/j/Y') ?? 'Now' }}
    @else
        &middot; Period: All time
    @endif
    @if ($range['supplier_id'])
        &middot; Supplier: {{ $orders->firstWhere('supplier_id', $range['supplier_id'])?->supplier?->name ?? 'Selected' }}
    @endif
@endsection

@section('body')
    <table class="summary">
        <tr>
            <td class="label">Total orders</td>
            <td class="value">{{ $orders->count() }}</td>
            <td class="label">Order value (excl. cancelled)</td>
            <td class="value">{{ number_format($totalSpend, 2) }}</td>
            <td class="label">Received value</td>
            <td class="value">{{ number_format($receivedSpend, 2) }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Order</th>
                <th>Order date</th>
                <th>Supplier</th>
                <th>Products</th>
                <th>Status</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>#{{ $order->id }}</td>
                    <td>{{ $order->order_date->format('n/j/Y') }}</td>
                    <td>{{ $order->supplier?->name ?? '—' }}</td>
                    <td>{{ $order->itemCount() }}</td>
                    <td>{{ ucfirst($order->status) }}</td>
                    <td class="text-right">{{ number_format($order->total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No purchase orders for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection