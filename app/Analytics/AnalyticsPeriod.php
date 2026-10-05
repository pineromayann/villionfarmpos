<?php

namespace App\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The date window every analytics page is scoped to.
 *
 * Analytics are always read through one of these so that the dashboard, the
 * individual reports and their exports can never drift apart: the same query
 * string always resolves to the same window, and every figure on a page is
 * describing that one window.
 *
 * Presets cover the ranges an operator actually asks for. `custom` is the
 * escape hatch and falls back to the default preset when the two bounds are
 * missing or inverted, so a half-typed filter can never produce an empty or
 * reversed range.
 */
final class AnalyticsPeriod
{
    /**
     * Preset keys mapped to their labels, in the order the filter offers them.
     *
     * @var array<string, string>
     */
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'last_year' => 'Last year',
        'custom' => 'Custom range',
    ];

    public const DEFAULT_PRESET = 'this_month';

    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $preset,
    ) {
        //
    }

    /**
     * Build the window from the request, validating the custom bounds.
     */
    public static function fromRequest(Request $request, string $default = self::DEFAULT_PRESET): self
    {
        $requested = (string) $request->query('period', $default);
        $preset = array_key_exists($requested, self::PRESETS) ? $requested : $default;

        if ($preset === 'custom') {
            $custom = self::custom($request->query('date_from'), $request->query('date_to'), $default);

            if ($custom !== null) {
                return $custom;
            }

            $preset = $default;
        }

        return self::forPreset($preset);
    }

    /**
     * An explicit window, used for the immediately preceding comparison range.
     */
    public static function between(Carbon $from, Carbon $to, string $preset = 'custom'): self
    {
        return new self($from, $to, $preset);
    }

    public static function forPreset(string $preset): self
    {
        [$from, $to] = match ($preset) {
            'today' => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()],
            'yesterday' => [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay()],
            'this_week' => [Carbon::now()->startOfWeek()->startOfDay(), Carbon::now()->endOfWeek()->endOfDay()],
            'last_week' => [
                Carbon::now()->subWeek()->startOfWeek()->startOfDay(),
                Carbon::now()->subWeek()->endOfWeek()->endOfDay(),
            ],
            'this_month' => [Carbon::now()->startOfMonth()->startOfDay(), Carbon::now()->endOfMonth()->endOfDay()],
            'last_month' => [
                Carbon::now()->subMonth()->startOfMonth()->startOfDay(),
                Carbon::now()->subMonth()->endOfMonth()->endOfDay(),
            ],
            'this_year' => [Carbon::now()->startOfYear()->startOfDay(), Carbon::now()->endOfYear()->endOfDay()],
            'last_year' => [
                Carbon::now()->subYear()->startOfYear()->startOfDay(),
                Carbon::now()->subYear()->endOfYear()->endOfDay(),
            ],
            default => [Carbon::now()->startOfMonth()->startOfDay(), Carbon::now()->endOfMonth()->endOfDay()],
        };

        return new self($from, $to, $preset);
    }

    /**
     * The custom window, or null when the bounds cannot be trusted.
     */
    private static function custom(mixed $from, mixed $to, string $fallback): ?self
    {
        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            return null;
        }

        try {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($start->greaterThan($end)) {
            return null;
        }

        return new self($start, $end, 'custom');
    }

    public function label(): string
    {
        if ($this->preset === 'custom') {
            return $this->from->format('j M Y').' - '.$this->to->format('j M Y');
        }

        return self::PRESETS[$this->preset] ?? self::PRESETS[self::DEFAULT_PRESET];
    }

    /**
     * Whole days covered, used to pick a sensible chart granularity.
     */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * Group sales by the smallest bucket that still keeps a long range readable.
     */
    public function grouping(): string
    {
        return match (true) {
            $this->days() <= 31 => 'day',
            $this->days() <= 400 => 'week',
            default => 'month',
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function query(): array
    {
        return [
            'period' => $this->preset,
            'date_from' => $this->from->toDateString(),
            'date_to' => $this->to->toDateString(),
        ];
    }
}
