@extends('reports.pdf.layout')

@section('title', 'Inventory Report')

@section('meta')
    Generated {{ $generatedAt->format('n/j/Y, g:i A') }} &middot; {{ $products->count() }} products
@endsection

@section('body')
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Active ingredient</th>
                <th>Batch</th>
                <th>Expiry</th>
                <th class="text-right">Price</th>
                <th class="text-right">Stock</th>
                <th>Flags</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                <tr>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->active_ingredient }}</td>
                    <td>{{ $product->batch_number }}</td>
                    <td>{{ $product->expiry_date?->format('n/j/Y') }}</td>
                    <td class="text-right">${{ number_format($product->price, 2) }}</td>
                    <td class="text-right">{{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}</td>
                    <td>
                        @if ($product->isLowStock())
                            Low stock
                        @endif
                        @if ($product->isLowStock() && $product->isExpiringSoon())
                            &middot;
                        @endif
                        @if ($product->isExpiringSoon())
                            Expiring soon
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No products in inventory.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
