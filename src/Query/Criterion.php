<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

final readonly class Criterion
{
    public function __construct(
        public string $field,
        public string $value,
    ) {
    }
}
