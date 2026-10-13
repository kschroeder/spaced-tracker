<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Config;

use SpacedTracker\Config\ClientConfig;
use PHPUnit\Framework\TestCase;

final class ClientConfigTest extends TestCase
{
    public function testDefaultsMatchThePublishedGuidance(): void
    {
        $config = new ClientConfig();

        self::assertSame('https://www.space-track.org', $config->normalizedBaseUrl());
        self::assertSame(60.0, $config->timeoutSeconds);
        self::assertTrue($config->verifyTls);
        self::assertSame(29, $config->ratePerMinute);
        self::assertSame(299, $config->ratePerHour);
        self::assertFalse($config->logPredicateValues);
        self::assertSame(7200, $config->sessionTtlSeconds);
        self::assertSame('auto', $config->concurrency);
        self::assertSame(
            'https://example.test/track',
            (new ClientConfig(baseUrl: 'https://example.test/track/'))->normalizedBaseUrl(),
        );
    }

    public function testRejectsLimitsUrlsAndStrategiesThatCannotBeUsed(): void
    {
        $cases = [
            static fn (): ClientConfig => new ClientConfig(ratePerMinute: 0),
            static fn (): ClientConfig => new ClientConfig(ratePerHour: 0),
            static fn (): ClientConfig => new ClientConfig(sessionTtlSeconds: 59),
            static fn (): ClientConfig => new ClientConfig(timeoutSeconds: 0),
            static fn (): ClientConfig => new ClientConfig(baseUrl: 'ftp://files.example'),
            static fn (): ClientConfig => new ClientConfig(concurrency: 'threads'),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Invalid client configuration must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
