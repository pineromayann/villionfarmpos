@props([
    'title',
    'state',
    'close' => null,
    'maxWidth' => 'max-w-lg',
])

@php
    $close ??= $state.' = false';
@endphp

<div
    x-show="{{ $state }}"
    x-cloak
    @keydown.escape.window="{{ $close }}"
    @click.self="{{ $close }}"
    class="fixed inset-0 z-50 flex items-center justify-center overflow-hidden bg-black/30 p-3 sm:p-4"
>
    <div
        role="dialog"
        aria-modal="true"
        aria-label="{{ strip_tags((string) $title) }}"
        class="flex max-h-full w-full {{ $maxWidth }} flex-col overflow-hidden rounded-xl bg-white shadow-xl"
    >
        <div class="flex shrink-0 items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 sm:px-6">
            <h2 class="text-lg font-semibold text-gray-900">{!! $title !!}</h2>
            <button
                type="button"
                @click="{{ $close }}"
                aria-label="Close"
                class="-mr-1.5 -mt-1 shrink-0 rounded-lg p-1 text-gray-400 hover:bg-gray-50 hover:text-gray-600"
            >
                <x-icon name="x" class="h-5 w-5" />
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4 sm:px-6 sm:py-5">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="shrink-0 border-t border-gray-200 bg-gray-50 px-5 py-3 sm:px-6">
                {{ $footer }}
            </div>
        @endif
    </div>
</div>