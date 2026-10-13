<?php

declare(strict_types=1);

namespace SpacedTracker\Config;

/**
 * Reads the same keys the framework packages publish.
 *
 * @phpstan-type ConfigArray array{
 *     identity: string,
 *     password: string,
 *     base_url: string,
 *     timeout: float,
 *     verify_tls: bool,
 *     rate_per_minute: int,
 *     rate_per_hour: int,
 *     log_predicate_values: bool,
 *     session_ttl: int,
 *     user_agent: string,
 *     enforce_advisory_limits: bool,
 *     concurrency: string
 * }
 */
final class EnvironmentConfig
{
    /**
     * @param array<string, string>|null $env
     * @return ConfigArray
     */
    public static function read(?array $env = null): array
    {
        return [
            'identity' => self::string('SPACETRACK_IDENTITY', $env, ''),
            'password' => self::string('SPACETRACK_PASSWORD', $env, ''),
            'base_url' => self::string('SPACETRACK_BASE_URL', $env, 'https://www.space-track.org'),
            'timeout' => self::float('SPACETRACK_TIMEOUT', $env, 60.0),
            'verify_tls' => self::bool('SPACETRACK_VERIFY_TLS', $env, true),
            'rate_per_minute' => self::int('SPACETRACK_RATE_PER_MINUTE', $env, 29),
            'rate_per_hour' => self::int('SPACETRACK_RATE_PER_HOUR', $env, 299),
            'log_predicate_values' => self::bool('SPACETRACK_LOG_PREDICATE_VALUES', $env, false),
            'session_ttl' => self::int('SPACETRACK_SESSION_TTL', $env, 7200),
            'user_agent' => self::string('SPACETRACK_USER_AGENT', $env, 'kschroeder-spaced-tracker/1.0'),
            'enforce_advisory_limits' => self::bool('SPACETRACK_ENFORCE_ADVISORY', $env, false),
            'concurrency' => self::string('SPACETRACK_CONCURRENCY', $env, 'auto'),
        ];
    }

    /**
     * @param array<string, string>|null $env
     */
    private static function string(string $key, ?array $env, string $default): string
    {
        $value = self::raw($key, $env);

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * @param array<string, string>|null $env
     */
    private static function int(string $key, ?array $env, int $default): int
    {
        $value = self::raw($key, $env);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @param array<string, string>|null $env
     */
    private static function float(string $key, ?array $env, float $default): float
    {
        $value = self::raw($key, $env);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }

    /**
     * @param array<string, string>|null $env
     */
    private static function bool(string $key, ?array $env, bool $default): bool
    {
        $value = self::raw($key, $env);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, string>|null $env
     */
    private static function raw(string $key, ?array $env): ?string
    {
        if ($env !== null) {
            return $env[$key] ?? null;
        }

        $value = getenv($key);

        return $value === false ? null : $value;
    }
}
