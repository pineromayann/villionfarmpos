@extends('reports.pdf.layout')

@section('title', 'Stock Movements Report')

@section('meta')
    Generated {{ $generatedAt->format('n/j/Y, g:i A') }}
    @if ($range['from'] || $range['to'])
        &middot; Period: {{ $range['from']?->format('n/j/Y') ?? 'Start' }} &ndash; {{ $range['to']?->format('n/j/Y') ?? 'Now' }}
    @else
        &middot; Period: All time
    @endif
    @if ($range['category'])
        &middot; Category: {{ ucfirst($range['category']) }}
    @endif
@endsection

@section('body')
    <table class="summary">
        <tr>
            <td class="label">Stock in</td>
            <td class="value">{{ number_format($totalIn, 2) }}</td>
            <td class="label">Stock out</td>
            <td class="value">{{ number_format($totalOut, 2) }}</td>
            <td class="label">Net change</td>
            <td class="value">{{ number_format($netChange, 2) }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Type</th>
                <th class="text-right">Quantity</th>
                <th>Reason</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($movements as $movement)
                <tr>
                    <td>{{ $movement->created_at->format('n/j/Y, g:i A') }}</td>
                    <td>{{ $movement->product?->name ?? 'Unknown product' }}</td>
                    <td>{{ ucfirst($movement->type) }}</td>
                    <td class="text-right">{{ number_format((float) $movement->quantity, 2) }}</td>
                    <td>{{ ucfirst($movement->reason ?? '') }}</td>
                    <td>
                        {{ $movement->supplier?->name ?? ($movement->ref_type === 'sale' ? "Sale #{$movement->ref_id}" : '') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No stock movements recorded for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection