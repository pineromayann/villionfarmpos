@extends('layouts.app')

@section('title', 'Suppliers')
@section('heading', 'Suppliers')
@section('subheading', 'Distributors and dealers you purchase stock from.')

@section('actions')
    <div x-data="{ open: false, search: '' }" class="flex items-center gap-3">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
                type="text"
                x-model="search"
                @input="window.dispatchEvent(new CustomEvent('supplier-search', { detail: $event.target.value }))"
                placeholder="Search..."
                class="w-56 rounded-lg border border-gray-200 py-2 pl-9 pr-3 text-sm focus:border-gray-400 focus:outline-none"
            >
        </div>

        <button @click="open = true" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            <x-icon name="plus" class="h-4 w-4" />
            Add supplier
        </button>

        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <div @click.outside="open = false" class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Add supplier</h2>
                    <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                        <x-icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                <form method="POST" action="{{ route('suppliers.store') }}" class="space-y-4">
                    @csrf
                    @include('suppliers.partials.fields')

                    <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                        Add supplier
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div x-data="{ search: '' }" @supplier-search.window="search = $event.detail" class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse ($suppliers as $supplier)
            <div
                x-data="{ open: false }"
                x-show="!search || {{ Illuminate\Support\Js::from(Str::lower($supplier->name.' '.$supplier->contact_person)) }}.includes(search.toLowerCase())"
                class="rounded-xl border border-gray-200 bg-white p-5"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <p class="font-semibold text-gray-900">{{ $supplier->name }}</p>
                        @if ($supplier->contact_person)
                            <p class="text-sm text-gray-500">{{ $supplier->contact_person }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <button @click="open = true" class="text-gray-400 hover:text-gray-700">
                            <x-icon name="pencil" class="h-4 w-4" />
                        </button>
                        <form method="POST" action="{{ route('suppliers.destroy', $supplier) }}" onsubmit="return confirm('Delete this supplier?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-gray-400 hover:text-red-600">
                                <x-icon name="trash" class="h-4 w-4" />
                            </button>
                        </form>
                    </div>
                </div>

                <div class="mt-3 space-y-1.5 text-sm text-gray-600">
                    @if ($supplier->phone)
                        <p class="flex items-center gap-2"><x-icon name="phone" class="h-4 w-4 text-gray-400" /> {{ $supplier->phone }}</p>
                    @endif
                    @if ($supplier->location)
                        <p class="flex items-center gap-2"><x-icon name="location" class="h-4 w-4 text-gray-400" /> {{ $supplier->location }}</p>
                    @endif
                </div>

                @if ($supplier->note)
                    <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">{{ $supplier->note }}</p>
                @endif

                <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 text-sm">
                    <span class="text-gray-500">Deliveries</span>
                    <span class="font-semibold text-gray-900">{{ $supplier->stock_movements_count }}</span>
                </div>

                <template x-teleport="body">
                    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
                        <div @click.outside="open = false" class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                            <div class="mb-4 flex items-center justify-between">
                                <h2 class="text-lg font-semibold text-gray-900">Edit supplier</h2>
                                <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                                    <x-icon name="x" class="h-5 w-5" />
                                </button>
                            </div>

                            <form method="POST" action="{{ route('suppliers.update', $supplier) }}" class="space-y-4">
                                @csrf
                                @method('PUT')
                                @include('suppliers.partials.fields', ['supplier' => $supplier])

                                <button type="submit" class="w-full rounded-lg bg-gray-900 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
                                    Save changes
                                </button>
                            </form>
                        </div>
                    </div>
                </template>
            </div>
        @empty
            <p class="col-span-full text-center text-gray-500">No suppliers on file yet.</p>
        @endforelse
    </div>
@endsection