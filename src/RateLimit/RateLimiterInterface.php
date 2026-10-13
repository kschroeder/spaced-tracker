<?php

declare(strict_types=1);

namespace SpacedTracker\RateLimit;

interface RateLimiterInterface
{
    /**
     * Block until a request slot is available, or throw when the wait is too long.
     */
    public function acquire(): void;
}
