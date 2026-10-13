<?php

declare(strict_types=1);

namespace SpacedTracker\Auth;

use Psr\Clock\ClockInterface;

final readonly class Session
{
    /**
     * @param array<string, string> $cookies
     */
    public function __construct(
        public array $cookies,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function headerValue(): string
    {
        $parts = [];
        foreach ($this->cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode('; ', $parts);
    }

    public function isExpired(ClockInterface $clock): bool
    {
        return $clock->now() >= $this->expiresAt;
    }
}
