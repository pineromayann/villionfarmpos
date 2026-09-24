@extends('reports.pdf.layout')

@section('title', 'Consignment Report')

@section('meta')
    Generated {{ $generatedAt->format('n/j/Y, g:i A') }}
    @if ($range['from'] || $range['to'])
        &middot; Period: {{ $range['from']?->format('n/j/Y') ?? 'Start' }} &ndash; {{ $range['to']?->format('n/j/Y') ?? 'Now' }}
    @else
        &middot; Period: All time
    @endif
    @if ($range['partner_id'])
        &middot; Partner: {{ $partners->first(fn ($p) => $p['name']) ?? 'Selected' }}
    @endif
@endsection

@section('body')
    <table class="summary">
        <tr>
            <td class="label">Retail value</td>
            <td class="value">{{ number_format($totalRetail, 2) }}</td>
            <td class="label">Store earned</td>
            <td class="value">{{ number_format($totalEarned, 2) }}</td>
            <td class="label">Balance due</td>
            <td class="value">{{ number_format($totalDue, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Returned</td>
            <td class="value">{{ number_format($totalReturned, 2) }}</td>
            <td class="label">Payable to partners</td>
            <td class="value">{{ number_format($totalPayable, 2) }}</td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <h1>Partner summary</h1>

    <table>
        <thead>
            <tr>
                <th>Partner</th>
                <th class="text-right">On-hand value</th>
                <th class="text-right">Sold payable</th>
                <th class="text-right">Damage / loss</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Balance due</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($partners as $partner)
                <tr>
                    <td>{{ $partner['name'] }}</td>
                    <td class="text-right">{{ number_format($partner['onHandValue'], 2) }}</td>
                    <td class="text-right">{{ number_format($partner['soldPayable'], 2) }}</td>
                    <td class="text-right">{{ number_format($partner['adjustments'], 2) }}</td>
                    <td class="text-right">{{ number_format($partner['settled'], 2) }}</td>
                    <td class="text-right">{{ number_format($partner['balanceDue'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No consignment partners on file.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h1>Consignment sales</h1>

    <table>
        <thead>
            <tr>
                <th>Sold at</th>
                <th>Partner</th>
                <th>Product</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Returned</th>
                <th class="text-right">Unit price</th>
                <th class="text-right">Line total</th>
                <th class="text-right">Payable</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $sale)
                <tr>
                    <td>{{ $sale->sold_at->format('n/j/Y H:i') }}</td>
                    <td>{{ $sale->partner?->name ?? '—' }}</td>
                    <td>{{ $sale->product->name }}</td>
                    <td class="text-right">{{ $sale->quantity }}</td>
                    <td class="text-right">{{ $sale->refunded_quantity > 0 ? $sale->refunded_quantity : '' }}</td>
                    <td class="text-right">{{ number_format($sale->unit_price, 2) }}</td>
                    <td class="text-right">{{ number_format($sale->netLineTotal(), 2) }}</td>
                    <td class="text-right">{{ number_format($sale->netPayable(), 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">No consignment sales in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection