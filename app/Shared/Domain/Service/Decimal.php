<?php

namespace App\Shared\Domain\Service;

use InvalidArgumentException;

/**
 * Exact base-10 arithmetic on canonical decimal strings (bcmath). Monetary amounts and rates never become floats.
 * Rounding is half away from zero, matching PostgreSQL round(numeric, scale).
 */
final class Decimal
{
    public static function isCanonical(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/D', $value) === 1;
    }

    public static function assert(string $value): string
    {
        if (! self::isCanonical($value)) {
            throw new InvalidArgumentException('Expected a canonical decimal string.');
        }

        return $value;
    }

    public static function scale(string $value): int
    {
        $dot = strpos(self::assert($value), '.');

        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    public static function add(string $a, string $b): string
    {
        return self::clean(bcadd(self::assert($a), self::assert($b), max(self::scale($a), self::scale($b))));
    }

    public static function subtract(string $a, string $b): string
    {
        return self::clean(bcsub(self::assert($a), self::assert($b), max(self::scale($a), self::scale($b))));
    }

    public static function multiply(string $a, string $b): string
    {
        // The product of exact operands is exact at the sum of their scales.
        return self::clean(bcmul($a, $b, self::scale($a) + self::scale($b)));
    }

    public static function divide(string $dividend, string $divisor, int $scale): string
    {
        if (self::compare($divisor, '0') === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        // bcdiv truncates; one guard digit decides half-away-from-zero rounding exactly.
        return self::round(bcdiv(self::assert($dividend), $divisor, $scale + 1), $scale);
    }

    public static function round(string $value, int $scale): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Scale must be non-negative.');
        }
        if (self::scale($value) <= $scale) {
            return self::clean(bcadd($value, '0', $scale));
        }
        $half = '0.'.str_repeat('0', $scale).'5';

        // bcadd truncates toward zero, so adding a signed half unit rounds ties away from zero.
        return self::clean(bcadd($value, str_starts_with($value, '-') ? '-'.$half : $half, $scale));
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::assert($a), self::assert($b), max(self::scale($a), self::scale($b)));
    }

    /** Removes insignificant fractional zeros without changing the value. */
    public static function trim(string $value): string
    {
        if (! str_contains(self::assert($value), '.')) {
            return self::clean($value);
        }

        return self::clean(rtrim(rtrim($value, '0'), '.'));
    }

    /** Trims, then pads to at least $scale fractional digits; never rounds away significant digits. */
    public static function atLeastScale(string $value, int $scale): string
    {
        $trimmed = self::trim($value);

        return self::scale($trimmed) >= $scale ? $trimmed : bcadd($trimmed, '0', $scale);
    }

    public static function integerDigits(string $value): int
    {
        return strlen(ltrim(explode('.', ltrim(self::assert($value), '-'))[0], '0'));
    }

    private static function clean(string $value): string
    {
        // Normalize negative zero produced by rounding (for example -0.001 at two decimals).
        return preg_match('/^-0(?:\.0+)?$/D', $value) === 1 ? substr($value, 1) : $value;
    }
}
