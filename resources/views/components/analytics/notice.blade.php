@props([
    'tone' => 'amber',
    'title' => null,
])

{{-- Explanatory banner: caveats the manager must read before trusting a figure. --}}
@php
    $tones = [
        'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
        'sky' => 'border-sky-200 bg-sky-50 text-sky-800',
        'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'red' => 'border-red-200 bg-red-50 text-red-800',
    ];
@endphp

<div {{ $attributes->class([
    'rounded-xl border p-4 text-sm leading-relaxed',
    $tones[$tone] ?? $tones['amber'],
]) }}>
    @if (filled($title))
        <p class="font-medium">{{ $title }}</p>
    @endif

    {{ $slot }}
</div>