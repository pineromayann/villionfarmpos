<?php

namespace App\Support;

/**
 * Formats money and quantities for display.
 *
 * Every analytics figure passes through here so the same peso amount never
 * appears two ways on two pages. Deliberately built on number_format rather
 * than ext-intl: the backup code in this application already carries a warning
 * that the intl extension cannot be assumed, and a shared host missing it would
 * take the entire analytics section down with it.
 *
 * The peso glyph lives in PHP rather than in Blade because the sign is not
 * reliably readable from a template file, and it is the one character every
 * figure on these pages needs.
 */
class Money
{
    /**
     * The peso sign.
     */
    public const PESO = "\u{20B1}";

    /**
     * A peso amount, e.g. ₱1,234.50.
     */
    public static function peso(float $amount, bool $signed = false): string
    {
        $formatted = self::PESO.number_format($amount, 2);

        return $signed && $amount > 0 ? '+'.$formatted : $formatted;
    }

    /**
     * A shortened peso amount for axis labels and tight tiles, e.g. ₱12.3K.
     */
    public static function pesoCompact(float $amount): string
    {
        $magnitude = abs($amount);

        return match (true) {
            $magnitude >= 1_000_000 => self::PESO.number_format($amount / 1_000_000, 1).'M',
            $magnitude >= 1_000 => self::PESO.number_format($amount / 1_000, 1).'K',
            default => self::PESO.number_format($amount, 0),
        };
    }

    /**
     * A percentage with one decimal, e.g. 12.3%.
     */
    public static function percent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals).'%';
    }

    /**
     * A quantity with no trailing zeros: 2, 1.5, 0.25.
     */
    public static function units(float $value, int $decimals = 2): string
    {
        $formatted = rtrim(rtrim(number_format($value, $decimals), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * A signed percentage change, or an em dash when there is nothing to
     * compare against.
     */
    public static function change(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value >= 0 ? '+' : '').number_format($value, 1).'%';
    }
}
