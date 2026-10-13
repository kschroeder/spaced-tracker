<?php

declare(strict_types=1);

namespace SpacedTracker\Testing;

use SpacedTracker\RateLimit\SleeperInterface;

final class AdvancingSleeper implements SleeperInterface
{
    public function __construct(private readonly FrozenClock $clock)
    {
    }

    public function sleep(float $seconds): void
    {
        $this->clock->advance($seconds);
    }
}
