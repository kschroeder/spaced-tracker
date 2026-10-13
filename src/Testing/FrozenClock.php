<?php

declare(strict_types=1);

namespace SpacedTracker\Testing;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $microseconds = (int) round($seconds * 1_000_000);
        $this->now = $this->now->modify(sprintf('%+d microseconds', $microseconds));
    }
}
