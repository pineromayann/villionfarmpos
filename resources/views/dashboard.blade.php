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
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Revenue today</p>
                <x-icon name="dollar" class="h-4 w-4 text-gray-400" />
            </div>
            <p class="mt-2 text-2xl font-bold text-gray-900">${{ number_format($revenueToday, 2) }}</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Total revenue</p>
                <x-icon name="trending-up" class="h-4 w-4 text-gray-400" />
            </div>
            <p class="mt-2 text-2xl font-bold text-gray-900">${{ number_format($totalRevenue, 2) }}</p>
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

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 lg:col-span-2">
            <h2 class="font-semibold text-gray-900">Last 7 days</h2>

            @php
                $max = max(4, (int) ceil($last7Days->max('total')));
                $width = 700;
                $height = 220;
                $padding = 28;
                $gridLines = 4;
                $step = $last7Days->count() > 1 ? ($width - $padding * 2) / ($last7Days->count() - 1) : 0;

                $points = $last7Days->values()->map(function ($day, $i) use ($padding, $step, $height, $max) {
                    $x = $padding + $i * $step;
                    $y = $height - $padding - (($day['total'] / $max) * ($height - $padding * 2));

                    return ['x' => $x, 'y' => $y, 'label' => $day['label']];
                });

                $polyline = $points->map(fn ($p) => "{$p['x']},{$p['y']}")->implode(' ');
            @endphp

            <svg viewBox="0 0 {{ $width }} {{ $height }}" class="mt-4 w-full">
                @for ($i = 0; $i <= $gridLines; $i++)
                    @php
                        $value = (int) round($max * $i / $gridLines);
                        $y = $height - $padding - (($value / $max) * ($height - $padding * 2));
                    @endphp
                    <line x1="{{ $padding }}" y1="{{ $y }}" x2="{{ $width - $padding }}" y2="{{ $y }}" stroke="#e5e7eb" stroke-dasharray="3,3" />
                    <text x="0" y="{{ $y + 4 }}" font-size="11" fill="#9ca3af">{{ $value }}</text>
                @endfor

                <polyline points="{{ $polyline }}" fill="none" stroke="#2563eb" stroke-width="2" />

                @foreach ($points as $p)
                    <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="3" fill="#2563eb" />
                    <text x="{{ $p['x'] }}" y="{{ $height - 6 }}" font-size="11" fill="#2563eb" text-anchor="middle">{{ $p['label'] }}</text>
                @endforeach
            </svg>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
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
                    @foreach ($lowStock as $product)
                        <li class="flex items-center justify-between text-sm">
                            <span class="text-gray-700">{{ $product->name }}</span>
                            <span class="font-medium text-red-600">{{ rtrim(rtrim(number_format($product->stock, 2), '0'), '.') }} {{ $product->unit }}</span>
                        </li>
                    @endforeach
                </ul>
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
        </div>
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
                    <p class="text-sm font-semibold text-gray-900">${{ number_format($sale->total, 2) }}</p>
                </li>
            @empty
                <li class="py-3 text-sm text-gray-500">No sales recorded yet.</li>
            @endforelse
        </ul>
    </div>

    <p class="mt-4 text-sm text-gray-500">{{ $farmsOnFile }} farm{{ $farmsOnFile === 1 ? '' : 's' }} on file.</p>
@endsection
