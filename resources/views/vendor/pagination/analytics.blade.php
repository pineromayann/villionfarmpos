@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs tabular-nums text-gray-500">
            Showing <span class="font-medium text-gray-900">{{ $paginator->firstItem() }}</span>–<span
                class="font-medium text-gray-900">{{ $paginator->lastItem() }}</span>
            of <span class="font-medium text-gray-900">{{ $paginator->total() }}</span>
        </p>

        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="cursor-default rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-300">
                    Previous
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                    Previous
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1.5 text-xs text-gray-400">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page"
                                  class="rounded-lg bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}"
                               class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs tabular-nums text-gray-600 hover:bg-gray-50">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                    Next
                </a>
            @else
                <span class="cursor-default rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-300">
                    Next
                </span>
            @endif
        </div>
    </nav>
@endif