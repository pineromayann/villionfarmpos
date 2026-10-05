@props([
    'isEmpty' => false,
    'empty' => 'Nothing to show for this period.',
])

{{--
    Table shell for analytics panels: scrolls sideways on narrow screens instead of
    bursting out of the card, keeps figures aligned with tabular numerals, and
    carries the empty state and an optional footer (pagination) in one place.
--}}
<div>
    @if ($isEmpty)
        <p class="px-5 py-12 text-center text-sm text-gray-400">{{ $empty }}</p>
    @else
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-full tabular-nums divide-y divide-gray-100 text-sm">
                {{ $slot }}
            </table>
        </div>
    @endif

    @if (! empty($footer))
        <div class="border-t border-gray-100 px-4 py-3 sm:px-5">{{ $footer }}</div>
    @endif
</div>