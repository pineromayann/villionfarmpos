@extends('layouts.app')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subheading', 'Overview of your insecticide operation.')

@section('actions')
    <a href="{{ route('pos.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
        <x-icon name="pos" class="h-4 w-4" />
        Open POS
    </a>
@endsection

@section('content')
    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Revenue today</p>
                <x-icon name="peso" class="h-4 w-4 text-gray-400" />
            </div>
            <p class="mt-2 text-2xl font-bold text-gray-900">₱{{ number_format($revenueToday, 2) }}</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Total revenue</p>
                <x-icon name="trending-up" class="h-4 w-4 text-gray-400" />
            </div>
            <p class="mt-2 text-2xl font-bold text-gray-900">₱{{ number_format($totalRevenue, 2) }}</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Products in stock</p>
                <x-icon name="box" class="h-4 w-4 text-gray-400" />
            </div>
            <p class="mt-2 text-2xl font-bold text-gray-900">{{ $productsInStock }}</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Sales recorded</p>
                <x-icon name="receipt" class="h-4 w-4 text-gray-400" />
            </div>
            <p class="mt-2 text-2xl font-bold text-gray-900">{{ $salesRecorded }}</p>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <a href="{{ route('procurement.index') }}" class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-5 hover:border-gray-300">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-50">
                    <x-icon name="cart" class="h-5 w-5 text-sky-600" />
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Pending purchase orders</p>
                    <p class="mt-0.5 text-xl font-bold text-gray-900">{{ $pendingOrders }}</p>
                </div>
            </div>
            <x-icon name="trending-up" class="h-4 w-4 text-gray-300" />
        </a>

        <a href="{{ route('procurement.index') }}" class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-5 hover:border-gray-300">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50">
                    <x-icon name="receipt" class="h-5 w-5 text-emerald-600" />
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Purchases this month</p>
                    <p class="mt-0.5 text-xl font-bold text-emerald-700">₱{{ number_format($spentThisMonth, 2) }}</p>
                </div>
            </div>
            <x-icon name="trending-up" class="h-4 w-4 text-gray-300" />
        </a>

        <a href="{{ route('consignment.settlement') }}" class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-5 hover:border-gray-300">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-violet-50">
                    <x-icon name="settlement" class="h-5 w-5 text-violet-600" />
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Consignment balance due</p>
                    <p class="mt-0.5 text-xl font-bold text-violet-700">₱{{ number_format($consignmentDue, 2) }}</p>
                    <p class="text-xs text-gray-500">{{ $consignmentPartners }} partner{{ $consignmentPartners === 1 ? '' : 's' }}</p>
                </div>
            </div>
            <x-icon name="trending-up" class="h-4 w-4 text-gray-300" />
        </a>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 lg:col-span-2">
            <h2 class="font-semibold text-gray-900">Revenue last 30 days</h2>

            @php
                $max = max(4, (int) ceil($last30Days->max('total')));
                $width = 700;
                $height = 240;
                $paddingT = 16;
                $paddingR = 12;
                $paddingB = 28;
                $paddingL = 40;
                $plotW = $width - $paddingL - $paddingR;
                $count = $last30Days->count();
                $barW = max(2, (int) floor(($plotW / max($count, 1)) * 0.55));
                $gap = $count > 1 ? ($plotW - $barW * $count) / ($count - 1) : 0;
                $gridLines = 4;
            @endphp

            <svg viewBox="0 0 {{ $width }} {{ $height }}" class="mt-4 w-full">
                @for ($i = 0; $i <= $gridLines; $i++)
                    @php
                        $value = (int) round($max * $i / $gridLines);
                        $y = $height - $paddingB - (($value / $max) * ($height - $paddingT - $paddingB));
                    @endphp
                    <line x1="{{ $paddingL }}" y1="{{ $y }}" x2="{{ $width - $paddingR }}" y2="{{ $y }}" stroke="#e5e7eb" stroke-dasharray="3,3" />
                    <text x="{{ $paddingL - 6 }}" y="{{ $y + 4 }}" font-size="11" fill="#9ca3af" text-anchor="end">{{ $value }}</text>
                @endfor

                @foreach ($last30Days->values() as $i => $day)
                    @php
                        $x = $paddingL + $i * ($barW + $gap);
                        $barHeight = max(0, round(($day['total'] / $max) * ($height - $paddingT - $paddingB)));
                        $y = $height - $paddingB - $barHeight;
                    @endphp
                    <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ $barHeight }}" rx="2" fill="#2563eb" />
                    @if ($i % 5 === 0 || $i === $count - 1)
                        <text x="{{ $x + $barW / 2 }}" y="{{ $height - 8 }}" font-size="10" fill="#9ca3af" text-anchor="middle">{{ $day['label'] }}</text>
                    @endif
                @endforeach
            </svg>
        </div>

        <div x-data="{ lowStockOpen: false }" class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-1.5 font-semibold text-gray-900">
                    <x-icon name="warning" class="h-4 w-4 text-amber-500" />
                    Attention
                </h2>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $lowStock->count() + $expiringSoon->count() }}</span>
            </div>

            @if ($lowStock->isNotEmpty())
                <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-400">Low stock</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($lowStock->take(5) as $product)
                        <li class="flex items-center justify-between text-sm">
                            <span class="truncate text-gray-700">{{ $product->name }}</span>
                            <span class="font-medium text-red-600">{{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($lowStock->count() > 5)
                    <button type="button" @click="lowStockOpen = true" class="mt-2.5 text-xs font-medium text-blue-600 hover:underline">
                        Show all {{ $lowStock->count() }} low-stock products
                    </button>
                @endif
            @endif

            @if ($expiringSoon->isNotEmpty())
                <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-400">Expiring within 6 months</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($expiringSoon as $product)
                        <li class="flex items-center justify-between text-sm">
                            <span class="text-gray-700">{{ $product->name }}</span>
                            <span class="font-medium text-gray-900">{{ $product->expiry_date->format('n/j/Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div
                x-show="lowStockOpen"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                @keydown.escape.window="lowStockOpen = false"
            >
                <div class="absolute inset-0 bg-black/40" @click="lowStockOpen = false"></div>
                <div class="relative w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-gray-900">Low stock products</h3>
                        <button type="button" @click="lowStockOpen = false" class="text-gray-400 hover:text-gray-600">
                            <x-icon name="x" class="h-5 w-5" />
                        </button>
                    </div>
                    <ul class="mt-4 max-h-80 space-y-1.5 overflow-y-auto pr-1">
                        @foreach ($lowStock as $product)
                            <li class="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm {{ $loop->index < 5 ? 'bg-gray-50' : '' }}">
                                <span class="truncate text-gray-700">{{ $product->name }}</span>
                                <span class="font-medium text-red-600">{{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="font-semibold text-gray-900">Top-selling products</h2>
            <p class="text-xs text-gray-500">Last 30 days by revenue</p>

            @if ($topProducts->isNotEmpty())
                @php $maxRevenue = max((float) $topProducts->max('revenue'), 1); @endphp
                <ul class="mt-4 space-y-3">
                    @foreach ($topProducts as $top)
                        <li>
                            <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                                <span class="truncate text-gray-700">{{ $top->name }}</span>
                                <span class="whitespace-nowrap font-medium text-gray-900">₱{{ number_format((float) $top->revenue, 2) }}</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-gray-100">
                                <div class="h-2 rounded-full bg-blue-600" style="width: {{ round(((float) $top->revenue / $maxRevenue) * 100) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-4 text-sm text-gray-500">No sales in the last 30 days.</p>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="font-semibold text-gray-900">Sales by payment method</h2>

            @php
                $paymentTotal = array_sum($paymentBreakdown);
                $paymentColors = ['#10b981', '#0ea5e9', '#8b5cf6'];
                $r = 40;
                $circ = 2 * pi() * $r;
            @endphp

            @if ($paymentTotal > 0)
                <div class="mt-4 flex justify-center">
                    <svg viewBox="0 0 100 100" class="h-40 w-40">
                        <g transform="rotate(-90 50 50)">
                            <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="#f3f4f6" stroke-width="12" />
                            @php $donutOffset = 0; @endphp
                            @foreach ($paymentBreakdown as $value)
                                @php
                                    $dash = ($value / $paymentTotal) * $circ;
                                @endphp
                                <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="{{ $paymentColors[$loop->index % count($paymentColors)] }}" stroke-width="12" stroke-dasharray="{{ round($dash, 2) }} {{ round($circ, 2) }}" stroke-dashoffset="{{ round(-$donutOffset, 2) }}" />
                                @php $donutOffset += $dash; @endphp
                            @endforeach
                        </g>
                    </svg>
                </div>

                <ul class="mt-4 space-y-2">
                    @foreach ($paymentBreakdown as $method => $value)
                        <li class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2 text-gray-700">
                                <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $paymentColors[$loop->index % count($paymentColors)] }}"></span>
                                {{ str($method)->replace('_', ' ')->title() }}
                            </span>
                            <span class="font-medium text-gray-900">₱{{ number_format((float) $value, 2) }} <span class="text-xs font-normal text-gray-400">{{ round(($value / $paymentTotal) * 100) }}%</span></span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-4 text-sm text-gray-500">No sales yet.</p>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="font-semibold text-gray-900">Stock value by category</h2>

            @if ($stockValueByCategory->isNotEmpty())
                @php $maxStockValue = max((float) $stockValueByCategory->max('value'), 1); @endphp
                <ul class="mt-4 space-y-3">
                    @foreach ($stockValueByCategory as $row)
                        <li>
                            <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                                <span class="capitalize text-gray-700">{{ $row->category }}</span>
                                <span class="whitespace-nowrap font-medium text-gray-900">₱{{ number_format((float) $row->value, 2) }}</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-gray-100">
                                <div class="h-2 rounded-full bg-emerald-600" style="width: {{ round(((float) $row->value / $maxStockValue) * 100) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-4 text-sm text-gray-500">No inventory yet.</p>
            @endif
        </div>
    </div>

    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-5">
        <h2 class="font-semibold text-gray-900">Consignment balance per partner</h2>

        @if ($consignmentBalances->isNotEmpty())
            @php $maxBalance = max((float) $consignmentBalances->max('balance'), 1); @endphp
            <ul class="mt-4 max-h-64 space-y-3 overflow-y-auto pr-1">
                @foreach ($consignmentBalances as $row)
                    <li>
                        <div class="mb-1 flex items-center justify-between gap-2 text-sm">
                            <span class="truncate text-gray-700">{{ $row['name'] }}</span>
                            <span class="whitespace-nowrap font-medium text-violet-700">₱{{ number_format((float) $row['balance'], 2) }}</span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-gray-100">
                            <div class="h-2 rounded-full bg-violet-600" style="width: {{ round(((float) $row['balance'] / $maxBalance) * 100) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-sm text-gray-500">No consignment balances yet.</p>
        @endif
    </div>

    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-5">
        <h2 class="font-semibold text-gray-900">Recent sales</h2>

        <ul class="mt-4 divide-y divide-gray-100">
            @forelse ($recentSales as $sale)
                <li class="flex items-center justify-between py-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ $sale->customer?->name ?? 'Walk-in customer' }}</p>
                        <p class="text-xs text-gray-500">{{ $sale->created_at->format('n/j/Y, g:i:s A') }} &middot; {{ $sale->itemCount() }} item{{ $sale->itemCount() === 1 ? '' : 's' }}</p>
                    </div>
                    <p class="text-sm font-semibold text-gray-900">₱{{ number_format($sale->total, 2) }}</p>
                </li>
            @empty
                <li class="py-3 text-sm text-gray-500">No sales recorded yet.</li>
            @endforelse
        </ul>
    </div>

    <p class="mt-4 text-sm text-gray-500">{{ $farmsOnFile }} farm{{ $farmsOnFile === 1 ? '' : 's' }} on file.</p>
@endsection
