@extends('reports.pdf.layout')

@section('title', 'Customers Report')

@section('meta')
    Generated {{ $generatedAt->format('n/j/Y, g:i A') }} &middot; {{ $customers->count() }} customers
@endsection

@section('body')
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Farm</th>
                <th>Phone</th>
                <th>Location</th>
                <th>Crop</th>
                <th class="text-right">Hectares</th>
                <th class="text-right">Lifetime spend</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($customers as $customer)
                <tr>
                    <td>{{ $customer->name }}</td>
                    <td>{{ $customer->farm_name }}</td>
                    <td>{{ $customer->phone }}</td>
                    <td>{{ $customer->location }}</td>
                    <td>{{ $customer->crop }}</td>
                    <td class="text-right">{{ $customer->hectares }}</td>
                    <td class="text-right">${{ number_format($customer->lifetimeSpend(), 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No customers on file.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
