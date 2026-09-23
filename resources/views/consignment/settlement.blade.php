@extends('layouts.app')

@section('title', 'Consignment Settlement')
@section('heading', 'Consignment Settlement')
@section('subheading', 'Cash paid to partners against the balance you owe them.')

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Total balance due</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700">₱{{ number_format($totalDue, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Partners</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $partners->count() }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Payments recorded</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $payments->count() }}</p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse ($partners as $entry)
            <div x-data="{ pay: false }" class="rounded-xl border border-gray-200 bg-white p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="font-semibold text-gray-900">{{ $entry['partner']->name }}</p>
                        <p class="text-sm text-gray-500">{{ $entry['consignments'] }} consignment{{ $entry['consignments'] === 1 ? '' : 's' }}</p>
                    </div>
                    <button @click="pay = true" class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                        Record payment
                    </button>
                </div>

                <div class="mt-4 space-y-2 border-t border-gray-100 pt-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">Payable on sold units</span>
                        <span class="font-semibold text-gray-900">₱{{ number_format($entry['soldPayable'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">Damage / loss write-offs</span>
                        <span class="font-semibold text-gray-900">₱{{ number_format($entry['adjustments'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">Already paid</span>
                        <span class="font-semibold text-gray-900">₱{{ number_format($entry['settled'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-gray-100 pt-2">
                        <span class="font-medium text-gray-700">Balance due</span>
                        <span class="font-bold text-emerald-700">₱{{ number_format($entry['balanceDue'], 2) }}</span>
                    </div>
                </div>

                <template x-teleport="body">
                    <div x-show="pay" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                        <div @click.outside="pay = false" class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                            <div class="mb-4 flex items-center justify-between">
                                <h2 class="text-lg font-semibold text-gray-900">Payment to {{ $entry['partner']->name }}</h2>
                                <button @click="pay = false" class="text-gray-400 hover:text-gray-600">
                                    <x-icon name="x" class="h-5 w-5" />
                                </button>
                            </div>

                            <p class="text-sm text-gray-600">Current balance due: <span class="font-semibold text-emerald-700">₱{{ number_format($entry['balanceDue'], 2) }}</span></p>

                            <form method="POST" action="{{ route('consignment.settlement.store') }}" class="mt-4 space-y-4">
                                @csrf
                                <input type="hidden" name="partner_id" value="{{ $entry['partner']->id }}">

                                <div>
                                    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="amount">Amount</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                </div>

                                <div>
                                    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="settled_at">Date</label>
                                    <input type="date" name="settled_at" value="{{ now()->toDateString() }}" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                </div>

                                <div>
                                    <label class="text-xs font-medium uppercase tracking-wide text-gray-500" for="note">Note</label>
                                    <input type="text" name="note" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                                </div>

                                <button type="submit" class="w-full rounded-lg bg-emerald-700 py-2.5 text-sm font-medium text-white hover:bg-emerald-800">
                                    Record payment
                                </button>
                            </form>
                        </div>
                    </div>
                </template>
            </div>
        @empty
            <p class="col-span-full text-center text-gray-500">No consignment partners on file yet.</p>
        @endforelse
    </div>

    <div class="mt-8">
        <h2 class="font-semibold text-gray-900">Payments made</h2>

        <div class="mt-3 overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3">Partner</th>
                        <th class="px-5 py-3">Note</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="px-5 py-3 text-gray-500">{{ $payment->settled_at->format('n/j/Y') }}</td>
                            <td class="px-5 py-3 font-medium text-gray-900">{{ $payment->partner?->name ?? '—' }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $payment->note ?? '' }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-emerald-700">₱{{ number_format((float) $payment->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-8 text-center text-gray-500">No payments recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection