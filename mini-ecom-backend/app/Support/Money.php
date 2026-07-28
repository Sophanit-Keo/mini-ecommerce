<?php

namespace App\Support;

/**
 * Decimal arithmetic for money and quantities.
 *
 * Every figure is a string, never a float. JSON numbers are IEEE-754 doubles in every
 * mainstream parser: `0.1 + 0.2` is `0.30000000000000004`, and a total that round-trips
 * through a double comes back a cent short — unreconcilable against a DECIMAL(10,2) column
 * and visible as a persistent discrepancy in month-end books.
 *
 * Money carries exactly two decimal places, weights and quantities exactly three.
 *
 * Rounding happens once per line, half-up, and never on a running subtotal:
 *
 *     line_total = round(unit_price × quantity, 2)
 *     subtotal   = sum(line_total)          // already rounded, not re-rounded
 *
 * Rounding each line then summing is reproducible and auditable — every figure on the
 * receipt is explained by a line the customer can see. Summing unrounded lines and rounding
 * the total produces a figure no individual line accounts for.
 */
final class Money
{
    public const SCALE = 2;

    public const QUANTITY_SCALE = 3;

    /**
     * Round half-up to `$scale` decimals.
     *
     * bcmath truncates rather than rounds, so half a unit in the last place is added before
     * the truncation does the work.
     */
    public static function round(string|float|int $value, int $scale = self::SCALE): string
    {
        $value = self::normalize($value);
        $negative = str_starts_with($value, '-');
        $magnitude = ltrim($value, '-');

        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($magnitude, $half, $scale);

        if ($negative && bccomp($rounded, '0', $scale) !== 0) {
            return '-'.$rounded;
        }

        return $rounded;
    }

    /**
     * A line total: unit price times quantity, rounded once.
     */
    public static function lineTotal(string|float|int $unitPrice, string|float|int $quantity): string
    {
        return self::round(
            bcmul(self::normalize($unitPrice), self::normalize($quantity), 8),
        );
    }

    public static function add(string|float|int $a, string|float|int $b, int $scale = self::SCALE): string
    {
        return bcadd(self::normalize($a), self::normalize($b), $scale);
    }

    public static function sub(string|float|int $a, string|float|int $b, int $scale = self::SCALE): string
    {
        return bcsub(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Sum values that have already been rounded. Does not re-round.
     *
     * @param  iterable<string|float|int>  $values
     */
    public static function sum(iterable $values, int $scale = self::SCALE): string
    {
        $total = bcadd('0', '0', $scale);

        foreach ($values as $value) {
            $total = bcadd($total, self::normalize($value), $scale);
        }

        return $total;
    }

    /**
     * Apply a percentage — tax, or the weight tolerance buffer on an authorisation.
     */
    public static function percentageOf(string|float|int $amount, string|float|int $percent): string
    {
        return self::round(
            bcdiv(bcmul(self::normalize($amount), self::normalize($percent), 8), '100', 8),
        );
    }

    public static function compare(string|float|int $a, string|float|int $b, int $scale = self::SCALE): int
    {
        return bccomp(self::normalize($a), self::normalize($b), $scale);
    }

    public static function isZero(string|float|int $value, int $scale = self::SCALE): bool
    {
        return self::compare($value, '0', $scale) === 0;
    }

    /**
     * bcmath rejects scientific notation, which is exactly how PHP stringifies small floats.
     */
    private static function normalize(string|float|int $value): string
    {
        if (is_string($value)) {
            return $value === '' ? '0' : $value;
        }

        return number_format((float) $value, 8, '.', '');
    }
}
