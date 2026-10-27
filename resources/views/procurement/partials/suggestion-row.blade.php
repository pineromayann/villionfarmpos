@php
    /** @var array<string, mixed> $suggestion */
    /** @var int $index */
    /** @var string $cell */
    $cell ??= 'px-5 py-3';
@endphp

<td class="{{ $cell }}">
    <input
        type="checkbox"
        x-model="rows[{{ $index }}].selected"
        @disabled(! $suggestion['orderable'])
        aria-label="Select {{ $suggestion['name'] }}"
        class="rounded border-gray-300 text-gray-900 focus:ring-gray-400 disabled:opacity-40"
    >
</td>
<td class="{{ $cell }}">
    <p class="font-medium text-gray-900">{{ $suggestion['name'] }}</p>
    @unless ($suggestion['orderable'])
        <p class="text-xs text-amber-600">No selling unit — cannot be ordered yet</p>
    @endunless
</td>
<td class="{{ $cell }} text-gray-700">{{ $suggestion['category'] ? ucfirst($suggestion['category']) : '—' }}</td>
<td class="{{ $cell }} font-medium text-red-600">
    {{ rtrim(rtrim(number_format($suggestion['stock'], 2), '0'), '.') }} {{ $suggestion['unit'] }}
</td>
<td class="{{ $cell }} text-gray-700">
    {{ rtrim(rtrim(number_format($suggestion['reorder_level'], 2), '0'), '.') }} {{ $suggestion['unit'] }}
</td>
<td class="{{ $cell }} font-semibold text-gray-900">
    {{ rtrim(rtrim(number_format($suggestion['quantity'], 2), '0'), '.') }} {{ $suggestion['unit'] }}
</td>
<td class="{{ $cell }} text-gray-700">
    @if ($suggestion['estimated_cost'] !== null)
        ₱{{ number_format($suggestion['estimated_cost'], 2) }}
    @else
        <span class="text-gray-400">No cost price</span>
    @endif
</td>
<td class="{{ $cell }}">
    <div class="flex items-center justify-end">
        <button
            type="button"
            @click="addOne(rows[{{ $index }}])"
            @disabled(! $suggestion['orderable'])
            title="Add to a new order"
            class="text-gray-400 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-40"
        >
            <x-icon name="plus" class="h-4 w-4" />
        </button>
    </div>
</td>
