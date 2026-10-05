@props([
    'label' => '',
    'value' => '',
    'change' => null,
    'hint' => null,
])

<div {{ $attributes->class([
    'min-w-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm',
]) }}>
    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 sm:text-xs">
        {{ $label }}
    </p>

    <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 sm:text-2xl">
        {{ $value }}
    </p>

    @if ($change !== null)
        <p @class([
            'mt-1 text-xs font-medium tabular-nums',
            'text-emerald-600' => $change >= 0,
            'text-red-600' => $change < 0,
        ])>
            {{ $change >= 0 ? '+' : '' }}{{ number_format((float) $change, 1) }}% vs previous period
        </p>
    @elseif ($hint !== null)
        <p class="mt-1 truncate text-xs text-gray-400" title="{{ $hint }}">{{ $hint }}</p>
    @endif
</div>