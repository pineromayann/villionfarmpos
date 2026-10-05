@php
    /**
     * Server-rendered donut chart, for share-of-total breakdowns.
     *
     * Each slice is an SVG circle with a dash offset, which keeps the geometry
     * exact at any size without a charting dependency. A single slice becomes a
     * full ring rather than an invisible gap, the total sits in the middle, and
     * the legend stacks under the ring on a phone so neither is squeezed.
     *
     * A ring can only show a share of something positive, so a negative measure
     * — a category that lost money, a margin that went the wrong way — is kept in
     * the legend with a red marker and called out in the caption rather than
     * being silently clamped to zero and dropped.
     *
     * @param array<int, array{label: string, value: float, color?: string}> $data
     */
    $palette = ['#059669', '#0ea5e9', '#8b5cf6', '#f59e0b', '#ef4444', '#14b8a6', '#ec4899', '#64748b'];
    $radius = 42;
    $circumference = 2 * M_PI * $radius;

    // Normalise once so the ring and the legend can never disagree on a colour.
    $rows = [];
    foreach ($data as $index => $row) {
        $rows[] = [
            'label' => (string) $row['label'],
            'value' => (float) $row['value'],
            'color' => $row['color'] ?? $palette[$index % count($palette)],
        ];
    }

    $hasNegative = collect($rows)->contains(fn (array $row) => $row['value'] < 0);
    $slices = array_values(array_filter($rows, fn (array $row) => $row['value'] > 0));
    $total = array_sum(array_column($slices, 'value'));
@endphp

@if ($total <= 0)
    <p class="py-12 text-center text-sm text-gray-400">
        {{ $hasNegative
            ? 'Nothing positive to break down — every row in this period was zero or a loss.'
            : 'No data for this period.' }}
    </p>
@else
    <div class="flex flex-col items-center gap-5 sm:flex-row sm:items-center sm:gap-6">
        <div class="relative shrink-0">
            <svg viewBox="0 0 100 100" class="h-32 w-32 -rotate-90 sm:h-40 sm:w-40" role="img"
                 aria-label="{{ collect($slices)->map(fn (array $row) => $row['label'].' '.\App\Support\Money::peso($row['value']))->implode(', ') }}">
                @php $offset = 0.0; @endphp
                @foreach ($slices as $row)
                    @php $dash = $row['value'] / $total * $circumference; @endphp
                    <circle
                        cx="50" cy="50" r="{{ $radius }}"
                        fill="none" stroke-width="12"
                        stroke="{{ $row['color'] }}"
                        stroke-dasharray="{{ round($dash, 2) }} {{ round($circumference - $dash, 2) }}"
                        stroke-dashoffset="{{ round(-$offset, 2) }}"
                    />
                    @php $offset += $dash; @endphp
                @endforeach
            </svg>

            {{-- Kept inside the ring: the hole is 72% of the ring, so the block is
                 capped well under that and wraps rather than running over a slice. --}}
            <div class="absolute inset-0 flex items-center justify-center">
                <div class="w-[60%] text-center leading-tight" title="{{ \App\Support\Money::peso($total) }}">
                    <span class="block text-[9px] font-medium uppercase tracking-wide text-gray-400">Total</span>
                    <span class="block break-words text-[11px] font-semibold tabular-nums text-gray-900 sm:text-sm">
                        {{ \App\Support\Money::pesoCompact($total) }}
                    </span>
                </div>
            </div>
        </div>

        <ul class="w-full min-w-0 flex-1 space-y-1.5 sm:max-h-72 sm:overflow-y-auto sm:pr-1">
            @foreach ($rows as $row)
                @php
                    $share = $row['value'] > 0 ? $row['value'] / $total * 100 : 0;
                @endphp
                <li class="flex items-center gap-2 text-sm">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full"
                          style="background-color: {{ $row['value'] < 0 ? '#ef4444' : $row['color'] }}"></span>
                    <span class="min-w-0 flex-1 truncate text-gray-600" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                    <span @class([
                        'shrink-0 font-medium tabular-nums',
                        'text-gray-900' => $row['value'] >= 0,
                        'text-red-600' => $row['value'] < 0,
                    ])>{{ \App\Support\Money::pesoCompact($row['value']) }}</span>
                    <span class="w-12 shrink-0 text-right text-xs tabular-nums text-gray-400">
                        {{ $row['value'] > 0 ? \App\Support\Money::percent($share, 0) : '—' }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($hasNegative)
        <p class="mt-3 text-xs text-gray-400">
            Rows marked red lost money and sit outside the ring; shares are taken against the
            {{ \App\Support\Money::peso($total) }} shown in the middle.
        </p>
    @endif
@endif