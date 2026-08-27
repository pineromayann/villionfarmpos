@extends('layouts.app')

@section('title', 'Point of Sale')
@section('heading', 'Point of Sale')
@section('subheading', 'Tap a product to add it to the cart.')

@section('content')
    <div
        x-data="{
            cart: [],
            discount: 0,
            paymentMethod: 'cash',
            customerId: '',
            search: '',
            addToCart(product) {
                const existing = this.cart.find(i => i.id === product.id);
                if (existing) {
                    if (existing.qty < product.stock) existing.qty++;
                } else if (product.stock > 0) {
                    this.cart.push({ id: product.id, name: product.name, price: product.price, unit: product.unit, stock: product.stock, qty: 1 });
                }
            },
            removeFromCart(index) {
                this.cart.splice(index, 1);
            },
            get subtotal() {
                return this.cart.reduce((sum, i) => sum + i.price * i.qty, 0);
            },
            get total() {
                return Math.max(0, this.subtotal - (parseFloat(this.discount) || 0));
            },
        }"
        class="grid grid-cols-1 gap-6 lg:grid-cols-3"
    >
        <div class="lg:col-span-2">
            <div class="mb-4 flex justify-end">
                <div class="relative w-full max-w-xs">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input
                        type="text"
                        x-model="search"
                        placeholder="Search products..."
                        class="w-full rounded-lg border border-gray-200 py-2 pl-9 pr-3 text-sm focus:border-gray-400 focus:outline-none"
                    >
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($products as $product)
                    <button
                        type="button"
                        x-show="!search || {{ Illuminate\Support\Js::from(Str::lower($product->name.' '.$product->active_ingredient)) }}.includes(search.toLowerCase())"
                        @click="addToCart({{ Illuminate\Support\Js::from(['id' => $product->id, 'name' => $product->name, 'price' => (float) $product->price, 'unit' => $product->unit, 'stock' => (float) $product->stock]) }})"
                        class="rounded-xl border border-gray-200 bg-white p-4 text-left hover:border-gray-300 hover:shadow-sm"
                    >
                        <div class="flex items-start justify-between">
                            <p class="font-semibold text-gray-900">{{ $product->name }}</p>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-sky-600">{{ $product->active_ingredient }}</p>
                        <div class="mt-3 flex items-center justify-between text-sm">
                            <span class="font-semibold text-gray-900">${{ number_format($product->price, 2) }}</span>
                            <span class="text-gray-400">exp {{ $product->expiry_date?->format('n/j/Y') }}</span>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="flex items-center gap-1.5 font-semibold text-gray-900">
                <x-icon name="receipt" class="h-4 w-4" />
                Current sale
            </h2>

            <form method="POST" action="{{ route('pos.store') }}" class="mt-4">
                @csrf
                <input type="hidden" name="cart" :value="JSON.stringify(cart.map(i => ({ product_id: i.id, qty: i.qty })))">

                <label class="block text-sm font-medium text-gray-700">Customer</label>
                <select name="customer_id" x-model="customerId" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none">
                    <option value="">Walk-in customer</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>

                <div class="mt-4 min-h-[5rem] rounded-lg border border-dashed border-gray-200">
                    <template x-if="cart.length === 0">
                        <p class="flex h-20 items-center justify-center text-sm text-gray-400">Cart is empty</p>
                    </template>

                    <ul class="divide-y divide-gray-100">
                        <template x-for="(item, index) in cart" :key="item.id">
                            <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-gray-900" x-text="item.name"></p>
                                    <div class="flex items-center gap-1.5 text-gray-500">
                                        <button type="button" @click="item.qty > 1 ? item.qty-- : removeFromCart(index)" class="rounded border border-gray-200 px-1.5 leading-5 hover:bg-gray-50">-</button>
                                        <span x-text="item.qty"></span>
                                        <button type="button" @click="item.qty < item.stock && item.qty++" class="rounded border border-gray-200 px-1.5 leading-5 hover:bg-gray-50">+</button>
                                        <span x-text="item.unit"></span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-900" x-text="'$' + (item.price * item.qty).toFixed(2)"></span>
                                    <button type="button" @click="removeFromCart(index)" class="text-gray-400 hover:text-red-600">
                                        <x-icon name="x" class="h-4 w-4" />
                                    </button>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="mt-4 flex items-center justify-between text-sm">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="font-medium text-gray-900" x-text="'$' + subtotal.toFixed(2)"></span>
                </div>

                <div class="mt-2 flex items-center justify-between text-sm">
                    <label class="text-gray-500">Discount</label>
                    <input type="number" name="discount" x-model="discount" min="0" step="0.01" class="w-24 rounded-lg border border-gray-300 px-2 py-1 text-right text-sm focus:border-gray-400 focus:outline-none">
                </div>

                <div class="mt-2 flex items-center justify-between text-sm">
                    <label class="text-gray-500">Payment</label>
                    <select name="payment_method" x-model="paymentMethod" class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-gray-400 focus:outline-none">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="mobile_money">Mobile Money</option>
                    </select>
                </div>

                <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-4">
                    <span class="font-semibold text-gray-900">Total</span>
                    <span class="text-lg font-bold text-gray-900" x-text="'$' + total.toFixed(2)"></span>
                </div>

                <button
                    type="submit"
                    :disabled="cart.length === 0"
                    class="mt-4 w-full rounded-lg bg-gray-900 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Complete sale
                </button>
            </form>
        </div>
    </div>
@endsection
