<?php

declare(strict_types=1);

namespace SpacedTracker\Config;

final readonly class ClientConfig
{
    public function __construct(
        public string $baseUrl = 'https://www.space-track.org',
        public float $timeoutSeconds = 60.0,
        public bool $verifyTls = true,
        public int $ratePerMinute = 29,
        public int $ratePerHour = 299,
        public bool $logPredicateValues = false,
        public int $sessionTtlSeconds = 7200,
        public string $userAgent = 'kschroeder-spaced-tracker/1.0',
        public bool $enforceAdvisoryLimits = false,
        public bool $emptyResultShow = true,
        public string $concurrency = 'auto',
    ) {
        if ($this->ratePerMinute < 1 || $this->ratePerHour < 1) {
            throw new \InvalidArgumentException('Rate limits must be positive.');
        }
        if ($this->sessionTtlSeconds < 60) {
            throw new \InvalidArgumentException('Session TTL must be at least 60 seconds.');
        }
        if ($this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Timeout must be positive.');
        }
        if (preg_match('#^https?://#i', $this->baseUrl) !== 1) {
            throw new \InvalidArgumentException('Base URL must start with http:// or https://.');
        }
        if (!in_array($this->concurrency, ['auto', 'sequential', 'curl', 'symfony', 'guzzle', 'amp'], true)) {
            throw new \InvalidArgumentException('Unknown concurrency strategy.');
        }
    }

    public function normalizedBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }
}
