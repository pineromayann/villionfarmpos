@php
    /**
     * Headline metric tile. Every analytics page leads with a row of these, so
     * the label, the value and the optional period-on-period change stay
     * consistent across the section.
     *
     * Thin wrapper around <x-analytics.stat> so pages keep the include-based
     * call style while the markup lives in one component.
     *
     * @param string $label
     * @param string|float|int $value
     * @param float|null $change percentage change, null when there is no prior figure
     * @param string $hint
     */
@endphp

<x-analytics.stat
    :label="$label"
    :value="$value"
    :change="$change ?? null"
    :hint="$hint ?? null"
/>