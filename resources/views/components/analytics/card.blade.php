@props([
    'title' => null,
    'subtitle' => null,
    'flush' => false,
])

{{--
    The single panel used by every analytics page. `flush` drops the body padding
    for panels whose content is a table or a chart that manages its own insets.
--}}
<section {{ $attributes->class([
    'min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm',
]) }}>
    @if (filled($title) || ! empty($actions))
        <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-gray-100 px-4 py-3 sm:px-5">
            <div class="min-w-0 flex-1">
                @if (filled($title))
                    <h2 class="text-sm font-semibold text-gray-900 sm:text-base">{{ $title }}</h2>
                @endif

                @if (filled($subtitle))
                    <p class="mt-0.5 text-xs leading-relaxed text-gray-500 sm:text-sm">{{ $subtitle }}</p>
                @endif
            </div>

            @if (! empty($actions))
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
            @endif
        </header>
    @endif

    <div class="{{ $flush ? '' : 'px-4 py-4 sm:px-5 sm:py-5' }}">
        {{ $slot }}
    </div>
</section>