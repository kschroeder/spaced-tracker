<?php

declare(strict_types=1);

namespace SpacedTracker\RateLimit;

final class NativeSleeper implements SleeperInterface
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
