<?php

declare(strict_types=1);

namespace SpacedTracker\RateLimit;

interface SleeperInterface
{
    public function sleep(float $seconds): void;
}
