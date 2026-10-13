<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Config;

use SpacedTracker\Config\EnvironmentConfig;
use PHPUnit\Framework\TestCase;

final class EnvironmentConfigTest extends TestCase
{
    /** @var list<string> */
    private const KEYS = [
        'SPACETRACK_IDENTITY',
        'SPACETRACK_PASSWORD',
        'SPACETRACK_BASE_URL',
        'SPACETRACK_TIMEOUT',
        'SPACETRACK_VERIFY_TLS',
        'SPACETRACK_RATE_PER_MINUTE',
        'SPACETRACK_RATE_PER_HOUR',
        'SPACETRACK_LOG_PREDICATE_VALUES',
        'SPACETRACK_SESSION_TTL',
        'SPACETRACK_USER_AGENT',
        'SPACETRACK_ENFORCE_ADVISORY',
        'SPACETRACK_CONCURRENCY',
    ];

    public function testAnEmptyMapUsesThePublishedDefaults(): void
    {
        self::assertSame([
            'identity' => '',
            'password' => '',
            'base_url' => 'https://www.space-track.org',
            'timeout' => 60.0,
            'verify_tls' => true,
            'rate_per_minute' => 29,
            'rate_per_hour' => 299,
            'log_predicate_values' => false,
            'session_ttl' => 7200,
            'user_agent' => 'kschroeder-spaced-tracker/1.0',
            'enforce_advisory_limits' => false,
            'concurrency' => 'auto',
        ], EnvironmentConfig::read([]));
    }

    public function testOverridesAcceptNumericStringsAndBooleanWords(): void
    {
        $config = EnvironmentConfig::read([
            'SPACETRACK_IDENTITY' => 'ada@example.com',
            'SPACETRACK_PASSWORD' => 'correct-horse-battery',
            'SPACETRACK_BASE_URL' => 'https://example.test/track',
            'SPACETRACK_TIMEOUT' => '12.5',
            'SPACETRACK_VERIFY_TLS' => 'YES',
            'SPACETRACK_RATE_PER_MINUTE' => '8',
            'SPACETRACK_RATE_PER_HOUR' => '40',
            'SPACETRACK_LOG_PREDICATE_VALUES' => 'on',
            'SPACETRACK_SESSION_TTL' => '120',
            'SPACETRACK_USER_AGENT' => 'catalog/1',
            'SPACETRACK_ENFORCE_ADVISORY' => 'true',
            'SPACETRACK_CONCURRENCY' => 'sequential',
        ]);

        self::assertSame('ada@example.com', $config['identity']);
        self::assertSame('correct-horse-battery', $config['password']);
        self::assertSame('https://example.test/track', $config['base_url']);
        self::assertSame(12.5, $config['timeout']);
        self::assertTrue($config['verify_tls']);
        self::assertSame(8, $config['rate_per_minute']);
        self::assertSame(40, $config['rate_per_hour']);
        self::assertTrue($config['log_predicate_values']);
        self::assertSame(120, $config['session_ttl']);
        self::assertSame('catalog/1', $config['user_agent']);
        self::assertTrue($config['enforce_advisory_limits']);
        self::assertSame('sequential', $config['concurrency']);
    }

    public function testBlankAndNonNumericValuesFallBack(): void
    {
        $config = EnvironmentConfig::read([
            'SPACETRACK_IDENTITY' => '',
            'SPACETRACK_PASSWORD' => '',
            'SPACETRACK_BASE_URL' => '',
            'SPACETRACK_TIMEOUT' => 'slow',
            'SPACETRACK_VERIFY_TLS' => 'off',
            'SPACETRACK_RATE_PER_MINUTE' => '',
            'SPACETRACK_RATE_PER_HOUR' => 'many',
            'SPACETRACK_LOG_PREDICATE_VALUES' => '0',
            'SPACETRACK_SESSION_TTL' => '  ',
            'SPACETRACK_USER_AGENT' => '',
            'SPACETRACK_ENFORCE_ADVISORY' => 'false',
            'SPACETRACK_CONCURRENCY' => '',
        ]);

        self::assertSame('', $config['identity']);
        self::assertSame('https://www.space-track.org', $config['base_url']);
        self::assertSame(60.0, $config['timeout']);
        self::assertFalse($config['verify_tls']);
        self::assertSame(29, $config['rate_per_minute']);
        self::assertSame(299, $config['rate_per_hour']);
        self::assertFalse($config['log_predicate_values']);
        self::assertSame(7200, $config['session_ttl']);
        self::assertSame('kschroeder-spaced-tracker/1.0', $config['user_agent']);
        self::assertFalse($config['enforce_advisory_limits']);
        self::assertSame('auto', $config['concurrency']);
    }

    public function testReadUsesTheProcessEnvironmentWhenNoMapIsPassed(): void
    {
        $previous = $this->captureEnvironment();
        try {
            foreach (self::KEYS as $key) {
                putenv($key);
            }
            putenv('SPACETRACK_IDENTITY=ada@example.com');
            putenv('SPACETRACK_PASSWORD=correct-horse-battery');
            putenv('SPACETRACK_TIMEOUT=15');
            putenv('SPACETRACK_VERIFY_TLS=no');
            putenv('SPACETRACK_CONCURRENCY=curl');

            $config = EnvironmentConfig::read();

            self::assertSame('ada@example.com', $config['identity']);
            self::assertSame('correct-horse-battery', $config['password']);
            self::assertSame(15.0, $config['timeout']);
            self::assertFalse($config['verify_tls']);
            self::assertSame('curl', $config['concurrency']);
            self::assertSame(29, $config['rate_per_minute']);
        } finally {
            $this->restoreEnvironment($previous);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function captureEnvironment(): array
    {
        $previous = [];
        foreach (self::KEYS as $key) {
            $value = getenv($key);
            $previous[$key] = $value === false ? null : $value;
        }

        return $previous;
    }

    /**
     * @param array<string, string|null> $previous
     */
    private function restoreEnvironment(array $previous): void
    {
        foreach ($previous as $key => $value) {
            if ($value === null) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $value);
        }
    }
}
