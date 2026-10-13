<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

final class Token
{
    public static function assert(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid %s.', $label));
        }
    }
}
