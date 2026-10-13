<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

final readonly class Order
{
    public function __construct(
        public string $field,
        public Direction $direction = Direction::Asc,
    ) {
    }
}
