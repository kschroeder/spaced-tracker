<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

use DateTimeInterface;

final class ValueEncoder
{
    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null-val';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Predicate values must be finite numbers.');
            }

            $formatted = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            if ($value === []) {
                throw new \InvalidArgumentException('Predicate value lists cannot be empty.');
            }

            $parts = [];
            foreach ($value as $item) {
                if (is_array($item) || (is_object($item) && !$item instanceof DateTimeInterface)) {
                    throw new \InvalidArgumentException('Predicate value lists must contain only scalars, nulls, or dates.');
                }
                $parts[] = self::encode($item);
            }

            return implode(',', $parts);
        }

        if (is_string($value)) {
            if ($value === '') {
                throw new \InvalidArgumentException('Predicate values cannot be empty. Use Operator::isNull() for null matches.');
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Predicate values cannot contain control characters.');
            }

            return $value;
        }

        throw new \InvalidArgumentException('Unsupported predicate value type.');
    }
}
