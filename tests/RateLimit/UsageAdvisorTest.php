<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\RateLimit;

use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Exception\AdvisoryLimitException;
use SpacedTracker\Exception\RateLimitException;
use SpacedTracker\Query\Query;
use SpacedTracker\RateLimit\SlidingWindowRateLimiter;
use SpacedTracker\RateLimit\SleeperInterface;
use SpacedTracker\RateLimit\UsageAdvisor;
use SpacedTracker\Testing\AdvancingSleeper;
use SpacedTracker\Testing\ArrayLogger;
use SpacedTracker\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsageAdvisorTest extends TestCase
{
    #[DataProvider('bulkWarnings')]
    public function testBulkClassesWarnWithThePublishedGuidance(RequestClass $class, string $needle): void
    {
        $logger = new ArrayLogger();
        $advisor = new UsageAdvisor(new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z')), $logger, false);

        $advisor->inspect(Query::create($class));
        $advisor->inspect(Query::create($class));

        self::assertTrue($logger->contains($needle));
        self::assertTrue($logger->contains('spacetrack.ratelimit.advisory'));
    }

    public function testSelectiveQueriesStayQuietAndEnforcementStopsARepeatBulkPull(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $logger = new ArrayLogger();
        $quiet = new UsageAdvisor($clock, $logger, true);
        $selective = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1);
        $quiet->inspect($selective);
        self::assertFalse($quiet->isBulk($selective));
        self::assertFalse($logger->contains('Bulk GP'));

        $history = Query::create(RequestClass::GpHistory)->where('NORAD_CAT_ID', 25544)->limit(30);
        self::assertFalse($quiet->isBulk($history));
        self::assertTrue($quiet->isBulk(Query::create(RequestClass::GpHistory)->where('NORAD_CAT_ID', 25544)->limit(31)));
        self::assertTrue($quiet->isBulk(Query::create(RequestClass::GpHistory)->where('NORAD_CAT_ID', 25544)));
        self::assertFalse($quiet->isBulk(Query::create(RequestClass::Satcat)->where('SATNAME', 'ISS')->limit(50)));
        self::assertTrue($quiet->isBulk(Query::create(RequestClass::Satcat)->where('SATNAME', 'ISS')->limit(101)));
        self::assertTrue($quiet->isBulk(Query::create(RequestClass::Announcement)));
        $quiet->inspect(Query::create(RequestClass::Announcement));
        self::assertFalse($logger->contains('announcement'));

        $enforced = new UsageAdvisor($clock, new ArrayLogger(), true);
        $bulk = Query::create(RequestClass::Gp);
        $enforced->inspect($bulk);
        $clock->advance(10);

        $this->expectException(AdvisoryLimitException::class);
        $enforced->inspect($bulk);
    }

    public function testTheHourWindowAndAStuckSleeperBothStopTheLimiter(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $hour = new SlidingWindowRateLimiter($clock, new AdvancingSleeper($clock), new ArrayLogger(), 100, 1);
        $hour->acquire();
        $before = $clock->now();
        $hour->acquire();
        self::assertGreaterThan(3000, $clock->now()->getTimestamp() - $before->getTimestamp());

        $stuck = new SlidingWindowRateLimiter($clock, new class implements SleeperInterface {
            public function sleep(float $seconds): void
            {
                unset($seconds);
            }
        }, new ArrayLogger(), 1, 10);
        $stuck->acquire();
        try {
            $stuck->acquire();
            self::fail('A sleeper that does not advance time must give up.');
        } catch (RateLimitException $exception) {
            self::assertStringContainsString('could not acquire', $exception->getMessage());
        }

        $capped = new SlidingWindowRateLimiter($clock, new AdvancingSleeper($clock), new ArrayLogger(), 1, 10, 1.0);
        $capped->acquire();
        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage('above the configured maximum');
        $capped->acquire();
    }

    /**
     * @return list<array{0: RequestClass, 1: string}>
     */
    public static function bulkWarnings(): array
    {
        return [
            [RequestClass::Gp, 'Bulk GP'],
            [RequestClass::Satcat, 'SATCAT'],
            [RequestClass::Boxscore, 'Boxscore'],
            [RequestClass::Decay, 'decay'],
            [RequestClass::Tip, 'TIP'],
            [RequestClass::Cdm, 'conjunction'],
            [RequestClass::CdmPublic, 'conjunction'],
            [RequestClass::PublicDownload, 'Cache downloaded files'],
        ];
    }
}
