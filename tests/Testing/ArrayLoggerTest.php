<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Testing;

use SpacedTracker\Testing\ArrayLogger;
use PHPUnit\Framework\TestCase;

final class ArrayLoggerTest extends TestCase
{
    public function testContainsSearchesTheMessageWhenTheContextCannotBeEncoded(): void
    {
        $logger = new ArrayLogger();
        $logger->info('password=secret', ['value' => INF]);

        self::assertTrue($logger->contains('secret'));
        self::assertFalse($logger->contains('not-in-the-message'));
    }
}
