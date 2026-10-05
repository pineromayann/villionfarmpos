@php
    /**
     * Server-rendered bar chart.
     *
     * Drawn as inline SVG rather than through a charting library: the project
     * ships only Alpine, and a dependency of that size does not earn its place
     * for a handful of bars. Because the geometry is computed in PHP, a chart
     * with a single data point still renders correctly and an empty series
     * degrades to a clear message instead of an axis with nothing on it.
     *
     * The plot scales with the container: bars share the width evenly when there
     * are few of them and fall back to a fixed minimum width once there are enough
     * to crowd each other, with the row scrolling sideways rather than squeezing
     * bars into invisibility. A negative value is drawn below the zero line
     * instead of being drawn upwards in red, so a net loss can never be mistaken
     * for a gain.
     *
     * A report may carry more than one measure per bucket, so the key holding
     * the plotted value is configurable: procurement returns `ordered` and
     * `received` side by side while most pages return a single `value`. Any
     * other measure on the row is surfaced in the hover title.
     *
     * @param array<int, array<string, float>> $data
     * @param string $valueKey
     */
    $valueKey = $valueKey ?? 'value';
    $buckets = count($data);
    $values = array_map(fn (array $row) => (float) ($row[$valueKey] ?? 0), $data);
    $max = $values === [] ? 0.0 : max(array_merge($values, [0.0]));
    $min = $values === [] ? 0.0 : min(array_merge($values, [0.0]));
    $extraKeys = array_values(array_diff(array_keys($data[0] ?? []), ['label', $valueKey]));

    // Reserve the strip below the zero line for negative values, then let the
    // positive area take what is left, so every column shares one baseline.
    // The plot height is a responsive class, so the areas above and below the
    // zero line are percentages: the bars then scale with whatever height the
    // viewport gives the chart.
    $negativeArea = $min < 0 ? abs($min) / ($max - $min) * 100 : 0;
    $positiveArea = 100 - $negativeArea;

    // Keep bars legible: past a few dozen buckets the row scrolls instead.
    $barWidth = match (true) {
        $buckets > 40 => 'min-w-[3px]',
        $buckets > 16 => 'min-w-[6px]',
        default => 'min-w-[10px]',
    };
    $labelStep = max(1, (int) ceil($buckets / 24));
    $total = array_sum($values);
    $zeroOffset = round($positiveArea, 2);
@endphp

@if ($max <= 0 && $min >= 0)
    <p class="py-12 text-center text-sm text-gray-400">No data for this period.</p>
@else
    <div class="flex gap-2">
        <div class="relative hidden w-14 shrink-0 sm:block h-36 sm:h-44">
            <span class="absolute right-0 top-0 -translate-y-1/2 text-[10px] tabular-nums text-gray-400">
                {{ \App\Support\Money::pesoCompact($max) }}
            </span>
            <span class="absolute right-0 -translate-y-1/2 text-[10px] tabular-nums text-gray-400"
                  style="top: {{ $zeroOffset }}%">0</span>
            @if ($min < 0)
                <span class="absolute bottom-0 right-0 translate-y-1/2 text-[10px] tabular-nums text-gray-400">
                    {{ \App\Support\Money::pesoCompact($min) }}
                </span>
            @endif
        </div>

        <div class="min-w-0 flex-1 overflow-x-auto">
            <div class="relative">
                <div class="pointer-events-none absolute inset-x-0 border-t border-dashed border-gray-200"
                     style="top: {{ $zeroOffset }}%"></div>
                <div class="pointer-events-none absolute inset-x-0 top-0 border-t border-gray-200"></div>

                <div class="relative flex h-36 items-stretch gap-px sm:h-44">
                    @foreach ($data as $row)
                        @php
                            $value = (float) ($row[$valueKey] ?? 0);
                            $tooltip = collect([$row['label'] ?? ''])
                                ->merge(array_map(
                                    fn (string $key) => \Illuminate\Support\Str::headline($key).': '.\App\Support\Money::peso((float) ($row[$key] ?? 0)),
                                    $extraKeys,
                                ))
                                ->merge([\App\Support\Money::peso($value)])
                                ->implode(' · ');
                            $bar = $max > 0 && $value > 0 ? $value / $max * 100 : 0;
                            $drop = $min < 0 && $value < 0 ? abs($value) / abs($min) * 100 : 0;
                        @endphp
                        <div class="flex flex-1 flex-col {{ $barWidth }}" title="{{ $tooltip }}">
                            <div class="flex items-end" style="height: calc({{ round($positiveArea, 2) }}% - 0.5px)">
                                @if ($bar > 0)
                                    <div @class([
                                        'w-full rounded-t bg-emerald-500/70',
                                        'min-h-[2px]' => $bar > 0,
                                    ]) style="height: {{ round($bar, 2) }}%"></div>
                                @endif
                            </div>
                            <div class="h-px shrink-0 bg-gray-300"></div>
                            <div class="shrink-0" style="height: calc({{ round($negativeArea, 2) }}% - 0.5px)">
                                @if ($drop > 0)
                                    <div class="w-full rounded-b bg-red-400/80" style="height: {{ round($drop, 2) }}%"></div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-1 flex gap-px" aria-hidden="true">
                    @foreach ($data as $index => $row)
                        <div @class([
                            'flex-1 truncate text-center text-[10px] text-gray-400',
                            $barWidth,
                            'invisible' => $index % $labelStep !== 0,
                        ])>{{ $row['label'] }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <p class="sr-only">
        {{ $buckets }} {{ $buckets === 1 ? 'bucket' : 'buckets' }} totalling {{ \App\Support\Money::peso($total) }},
        peaking at {{ \App\Support\Money::peso($max) }}.
    </p>
    <p class="mt-2 text-xs text-gray-400">
        Peak {{ \App\Support\Money::peso($max) }} · total {{ \App\Support\Money::peso($total) }}
        @if ($buckets > 24)
            · scroll sideways for the full range
        @endif
    </p>
@endif