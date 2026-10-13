<?php

declare(strict_types=1);

namespace SpacedTracker\RateLimit;

use SpacedTracker\Exception\RateLimitException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the client under Space-Track's published global ceilings.
 *
 * The defaults are 29 requests per minute and 299 per hour, one below the
 * published 30 per minute and 300 per hour limits.
 */
final class SlidingWindowRateLimiter implements RateLimiterInterface
{
    /** @var list<float> */
    private array $hits = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly SleeperInterface $sleeper,
        private readonly LoggerInterface $logger,
        private readonly int $perMinute = 29,
        private readonly int $perHour = 299,
        private readonly float $maxWaitSeconds = 3700.0,
    ) {
        if ($this->perMinute < 1 || $this->perHour < 1) {
            throw new \InvalidArgumentException('Rate limits must be positive.');
        }
    }

    public function acquire(): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $now = $this->clock->now();
            $nowTs = $this->timestamp($now);
            $this->prune($nowTs);
            $wait = $this->waitSeconds($nowTs);
            if ($wait <= 0) {
                $this->hits[] = $nowTs;

                return;
            }
            if ($wait > $this->maxWaitSeconds) {
                throw new RateLimitException(sprintf(
                    'Space-Track rate limit would require waiting %.1f seconds, above the configured maximum.',
                    $wait,
                ));
            }

            $this->logger->info('Waiting for the Space-Track rate limit window.', [
                'event' => 'spacetrack.ratelimit.wait',
                'seconds' => round($wait, 3),
            ]);
            $this->sleeper->sleep($wait);
        }

        throw new RateLimitException('The Space-Track rate limiter could not acquire a request slot.');
    }

    private function waitSeconds(float $nowTs): float
    {
        $minuteHits = array_values(array_filter(
            $this->hits,
            static fn (float $hit): bool => $hit > $nowTs - 60,
        ));
        $waits = [0.0];
        if (count($minuteHits) >= $this->perMinute) {
            $waits[] = 60 - ($nowTs - $minuteHits[0]) + 0.01;
        }
        if (count($this->hits) >= $this->perHour) {
            $waits[] = 3600 - ($nowTs - $this->hits[0]) + 0.01;
        }

        return max($waits);
    }

    private function prune(float $nowTs): void
    {
        $cutoff = $nowTs - 3600;
        $this->hits = array_values(array_filter(
            $this->hits,
            static fn (float $hit): bool => $hit > $cutoff,
        ));
    }

    private function timestamp(\DateTimeImmutable $time): float
    {
        return (float) $time->format('U.u');
    }
}
