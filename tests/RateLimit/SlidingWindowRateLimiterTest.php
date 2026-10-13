<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\RateLimit;

use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Exception\AdvisoryLimitException;
use SpacedTracker\Query\Query;
use SpacedTracker\RateLimit\SlidingWindowRateLimiter;
use SpacedTracker\RateLimit\UsageAdvisor;
use SpacedTracker\Testing\AdvancingSleeper;
use SpacedTracker\Testing\ArrayLogger;
use SpacedTracker\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;

final class SlidingWindowRateLimiterTest extends TestCase
{
    public function testWaitsWhenTheMinuteWindowIsFull(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $logger = new ArrayLogger();
        $limiter = new SlidingWindowRateLimiter($clock, new AdvancingSleeper($clock), $logger, 2, 10);

        $limiter->acquire();
        $limiter->acquire();
        $before = $clock->now();
        $limiter->acquire();

        self::assertGreaterThan($before, $clock->now());
        self::assertTrue($logger->contains('rate limit'));
    }

    public function testRejectsANonPositiveLimit(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $sleeper = new AdvancingSleeper($clock);
        $logger = new ArrayLogger();

        try {
            new SlidingWindowRateLimiter($clock, $sleeper, $logger, 0, 10);
            self::fail('A zero per-minute limit must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Rate limits must be positive.', $exception->getMessage());
        }

        try {
            new SlidingWindowRateLimiter($clock, $sleeper, $logger, 10, 0);
            self::fail('A zero per-hour limit must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Rate limits must be positive.', $exception->getMessage());
        }
    }

    public function testBulkHistoryIsRejectedWhenEnforcementIsOn(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $advisor = new UsageAdvisor($clock, new ArrayLogger(), true);
        $query = Query::create(RequestClass::GpHistory);

        $advisor->inspect($query);
        $clock->advance(10);

        $this->expectException(AdvisoryLimitException::class);
        $advisor->inspect($query);
    }

    public function testASingleObjectDoesNotTripTheSatcatAdvisory(): void
    {
        $advisor = new UsageAdvisor(new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z')), new ArrayLogger(), true);
        $query = Query::create(RequestClass::Satcat)->where('NORAD_CAT_ID', 25544)->limit(1);

        $advisor->inspect($query);
        $advisor->inspect($query);

        self::assertFalse($advisor->isBulk($query));
    }
}
