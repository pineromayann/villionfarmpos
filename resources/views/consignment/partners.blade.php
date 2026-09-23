@extends('layouts.app')

@section('title', 'Consignment Partners')
@section('heading', 'Consignment Partners')
@section('subheading', 'Suppliers who stock products through your store on consignment.')

@section('actions')
    <div x-data="{ open: false, search: '' }" class="flex items-center gap-3">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
                type="text"
                x-model="search"
                @input="window.dispatchEvent(new CustomEvent('partner-search', { detail: $event.target.value }))"
                placeholder="Search..."
                class="w-56 rounded-lg border border-gray-200 py-2 pl-9 pr-3 text-sm focus:border-gray-400 focus:outline-none"
            >
        </div>

        <button @click="open = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="plus" class="h-4 w-4" />
            Add partner
        </button>

        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <div @click.outside="open = false" class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Add consignment partner</h2>
                    <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                        <x-icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                <form method="POST" action="{{ route('consignment-partners.store') }}" class="space-y-4">
                    @csrf
                    @include('consignment.partials.partner-fields')

                    <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                        Add partner
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Balance due to partners</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700">₱{{ number_format($totalDue, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Consigned stock on hand</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">₱{{ number_format($onHandValue, 2) }}</p>
        </div>
    </div>

    <div x-data="{ search: '' }" @partner-search.window="search = $event.detail" class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse ($partners as $partner)
            <div
                x-data="{ open: false }"
                x-show="!search || {{ Illuminate\Support\Js::from(Str::lower($partner->name.' '.$partner->contact_person)) }}.includes(search.toLowerCase())"
                class="rounded-xl border border-gray-200 bg-white p-5"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <p class="font-semibold text-gray-900">{{ $partner->name }}</p>
                        @if ($partner->contact_person)
                            <p class="text-sm text-gray-500">{{ $partner->contact_person }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <button @click="open = true" class="text-gray-400 hover:text-gray-700">
                            <x-icon name="pencil" class="h-4 w-4" />
                        </button>
                        <form method="POST" action="{{ route('consignment-partners.destroy', $partner) }}" onsubmit="return confirm('Delete this consignment partner? Its records will be kept without a partner.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-gray-400 hover:text-red-600">
                                <x-icon name="trash" class="h-4 w-4" />
                            </button>
                        </form>
                    </div>
                </div>

                <div class="mt-3 space-y-1.5 text-sm text-gray-600">
                    @if ($partner->phone)
                        <p class="flex items-center gap-2"><x-icon name="phone" class="h-4 w-4 text-gray-400" /> {{ $partner->phone }}</p>
                    @endif
                    @if ($partner->location)
                        <p class="flex items-center gap-2"><x-icon name="location" class="h-4 w-4 text-gray-400" /> {{ $partner->location }}</p>
                    @endif
                </div>

                @if ($partner->note)
                    <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">{{ $partner->note }}</p>
                @endif

                <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 text-sm">
                    <span class="text-gray-500">Consignments</span>
                    <span class="font-semibold text-gray-900">{{ $partner->consignments_count }}</span>
                </div>

                <div class="mt-2 flex items-center justify-between text-sm">
                    <span class="text-gray-500">Consigned sales</span>
                    <span class="font-semibold text-gray-900">{{ $partner->sales_count }}</span>
                </div>

                <div class="mt-2 flex items-center justify-between text-sm">
                    <span class="text-gray-500">On-hand value</span>
                    <span class="font-semibold text-gray-900">₱{{ number_format((float) $partner->on_hand_value, 2) }}</span>
                </div>

                <div class="mt-2 flex items-center justify-between text-sm">
                    <span class="text-gray-500">Balance due</span>
                    <span :class="({{ Illuminate\Support\Js::from((float) $partner->balance_due) }}) >= 0 ? 'text-emerald-700' : 'text-red-600'" class="font-semibold">
                        ₱{{ number_format((float) $partner->balance_due, 2) }}
                    </span>
                </div>

                <template x-teleport="body">
                    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                        <div @click.outside="open = false" class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                            <div class="mb-4 flex items-center justify-between">
                                <h2 class="text-lg font-semibold text-gray-900">Edit partner</h2>
                                <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                                    <x-icon name="x" class="h-5 w-5" />
                                </button>
                            </div>

                            <form method="POST" action="{{ route('consignment-partners.update', $partner) }}" class="space-y-4">
                                @csrf
                                @method('PUT')
                                @include('consignment.partials.partner-fields', ['consignmentPartner' => $partner])

                                <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                                    Save changes
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
@endsection