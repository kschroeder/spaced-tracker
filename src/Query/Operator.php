<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

/**
 * Space-Track REST operators.
 *
 * The strings returned here are predicate values, not full path segments.
 * `like` follows the widely used `~~pattern` form. `relativeToNow(-10)`
 * becomes `now-10`, which Space-Track treats as ten days before the request.
 */
final class Operator
{
    public static function greaterThan(mixed $value): string
    {
        return '>' . ValueEncoder::encode($value);
    }

    public static function lessThan(mixed $value): string
    {
        return '<' . ValueEncoder::encode($value);
    }

    public static function notEqual(mixed $value): string
    {
        return '<>' . ValueEncoder::encode($value);
    }

    public static function between(mixed $left, mixed $right): string
    {
        return ValueEncoder::encode($left) . '--' . ValueEncoder::encode($right);
    }

    public static function like(string $pattern): string
    {
        return '~~' . ValueEncoder::encode($pattern);
    }

    public static function startsWith(string $prefix): string
    {
        return '^' . ValueEncoder::encode($prefix);
    }

    public static function isNull(): string
    {
        return 'null-val';
    }

    /**
     * @param list<mixed> $values
     */
    public static function anyOf(array $values): string
    {
        return ValueEncoder::encode($values);
    }

    public static function relativeToNow(int $days): string
    {
        if ($days === 0) {
            return 'now';
        }

        return 'now' . ($days > 0 ? '+' . $days : (string) $days);
    }
}
