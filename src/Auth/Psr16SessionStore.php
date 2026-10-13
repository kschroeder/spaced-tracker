<?php

declare(strict_types=1);

namespace SpacedTracker\Auth;

use Psr\SimpleCache\CacheInterface;

/**
 * Shares one Space-Track session across PHP workers.
 *
 * The cache value is the cookie header and two timestamps. The password is
 * never written. Requires psr/simple-cache at runtime.
 */
final class Psr16SessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $key = 'kschroeder.spaced-tracker.session',
    ) {
    }

    public function get(): ?Session
    {
        $stored = $this->cache->get($this->key);
        if (!is_array($stored) || !isset($stored['cookies'], $stored['created_at'], $stored['expires_at'])) {
            return null;
        }
        if (!is_array($stored['cookies']) || !is_string($stored['created_at']) || !is_string($stored['expires_at'])) {
            return null;
        }

        $cookies = [];
        foreach ($stored['cookies'] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $cookies[$name] = $value;
            }
        }
        if ($cookies === []) {
            return null;
        }

        $created = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $stored['created_at']);
        $expires = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $stored['expires_at']);
        if (!$created instanceof \DateTimeImmutable || !$expires instanceof \DateTimeImmutable) {
            return null;
        }

        return new Session($cookies, $created, $expires);
    }

    public function put(Session $session): void
    {
        $ttl = max(1, $session->expiresAt->getTimestamp() - time());
        $this->cache->set($this->key, [
            'cookies' => $session->cookies,
            'created_at' => $session->createdAt->format(\DateTimeInterface::ATOM),
            'expires_at' => $session->expiresAt->format(\DateTimeInterface::ATOM),
        ], $ttl);
    }

    public function clear(): void
    {
        $this->cache->delete($this->key);
    }
}
