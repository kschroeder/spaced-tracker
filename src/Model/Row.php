<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function value(array $row, string $key): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $wanted = strtoupper($key);
        foreach ($row as $name => $value) {
            if (strtoupper((string) $name) === $wanted) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key): ?string
    {
        $value = self::value($row, $key);
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): ?int
    {
        $value = self::value($row, $key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function float(array $row, string $key): ?float
    {
        $value = self::value($row, $key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
