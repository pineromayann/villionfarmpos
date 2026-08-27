@extends('layouts.app')

@section('title', 'Reports')
@section('heading', 'Reports')
@section('subheading', 'Generate PDF and CSV exports from your sales, inventory and customer data.')

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center gap-2">
                <x-icon name="sales" class="h-5 w-5 text-gray-400" />
                <h2 class="font-semibold text-gray-900">Sales report</h2>
            </div>
            <p class="mt-1 text-sm text-gray-500">Transactions, revenue and top products for a given period.</p>

            <form method="GET" class="mt-4 space-y-3">
                <div>
                    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="date_from">From</label>
                    <input type="date" name="date_from" id="date_from" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="date_to">To</label>
                    <input type="date" name="date_to" id="date_to" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                </div>

                <div class="flex gap-2 pt-1">
                    <button type="submit" formaction="{{ route('reports.sales.pdf') }}" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                        Export PDF
                    </button>
                    <button type="submit" formaction="{{ route('reports.sales.csv') }}" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Export CSV
                    </button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center gap-2">
                <x-icon name="inventory" class="h-5 w-5 text-gray-400" />
                <h2 class="font-semibold text-gray-900">Inventory report</h2>
            </div>
            <p class="mt-1 text-sm text-gray-500">Full stock list with batch numbers, expiry and low-stock flags.</p>

            <div class="mt-4 flex gap-2">
                <a href="{{ route('reports.inventory.pdf') }}" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                    Export PDF
                </a>
                <a href="{{ route('reports.inventory.csv') }}" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Export CSV
                </a>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center gap-2">
                <x-icon name="customers" class="h-5 w-5 text-gray-400" />
                <h2 class="font-semibold text-gray-900">Customers report</h2>
            </div>
            <p class="mt-1 text-sm text-gray-500">Farmer contacts, farm details and lifetime spend.</p>

            <div class="mt-4 flex gap-2">
                <a href="{{ route('reports.customers.pdf') }}" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                    Export PDF
                </a>
                <a href="{{ route('reports.customers.csv') }}" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Export CSV
                </a>
            </div>
        </div>
    </div>
@endsection
