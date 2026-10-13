<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Auth;

use SpacedTracker\Auth\Psr16SessionStore;
use SpacedTracker\Auth\Session;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class Psr16SessionStoreTest extends TestCase
{
    public function testRoundTripsCookiesWithoutThePassword(): void
    {
        $cache = $this->cache();
        $store = new Psr16SessionStore($cache, 'session-key');
        $created = new \DateTimeImmutable('now');
        $expires = $created->modify('+2 hours');
        $store->put(new Session([
            'chocolatechip' => 'session-token',
            12 => 'ignored',
        ], $created, $expires));

        $stored = $cache->values['session-key'];
        self::assertIsArray($stored);
        self::assertSame([
            'chocolatechip' => 'session-token',
            12 => 'ignored',
        ], $stored['cookies']);
        self::assertSame($created->format(\DateTimeInterface::ATOM), $stored['created_at']);
        self::assertSame($expires->format(\DateTimeInterface::ATOM), $stored['expires_at']);
        self::assertGreaterThan(7000, $cache->ttl);
        self::assertLessThanOrEqual(7200, $cache->ttl);
        self::assertStringNotContainsString('correct-horse-battery', (string) json_encode($stored));

        $loaded = $store->get();
        self::assertInstanceOf(Session::class, $loaded);
        self::assertSame('chocolatechip=session-token', $loaded->headerValue());
        self::assertSame($expires->format(\DateTimeInterface::ATOM), $loaded->expiresAt->format(\DateTimeInterface::ATOM));
        self::assertSame($created->format(\DateTimeInterface::ATOM), $loaded->createdAt->format(\DateTimeInterface::ATOM));

        $store->clear();
        self::assertNull($store->get());
        self::assertArrayNotHasKey('session-key', $cache->values);
    }

    public function testExpiredSessionsStillHaveAPositiveCacheTtl(): void
    {
        $cache = $this->cache();
        $store = new Psr16SessionStore($cache);
        $store->put(new Session(
            ['chocolatechip' => 'session-token'],
            new \DateTimeImmutable('2020-01-01T00:00:00Z'),
            new \DateTimeImmutable('2020-01-01T00:00:01Z'),
        ));

        self::assertSame(1, $cache->ttl);
    }

    public function testMalformedCacheValuesAreMisses(): void
    {
        $cache = $this->cache();
        $store = new Psr16SessionStore($cache);
        $validDate = (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->format(\DateTimeInterface::ATOM);
        $shapes = [
            'nope',
            ['cookies' => ['chocolatechip' => 'token']],
            ['cookies' => 'token', 'created_at' => $validDate, 'expires_at' => $validDate],
            ['cookies' => [], 'created_at' => $validDate, 'expires_at' => $validDate],
            ['cookies' => [1 => 'token'], 'created_at' => $validDate, 'expires_at' => $validDate],
            ['cookies' => ['chocolatechip' => 'token'], 'created_at' => 'yesterday', 'expires_at' => $validDate],
            [
                'cookies' => ['chocolatechip' => 'token', 'drop' => 5],
                'created_at' => $validDate,
                'expires_at' => $validDate,
            ],
        ];

        foreach ($shapes as $shape) {
            $cache->values['kschroeder.spaced-tracker.session'] = $shape;
            $complete = is_array($shape)
                && isset($shape['cookies']['chocolatechip'], $shape['created_at'], $shape['expires_at'])
                && $shape['created_at'] === $validDate
                && $shape['expires_at'] === $validDate;
            if ($complete) {
                $loaded = $store->get();
                self::assertInstanceOf(Session::class, $loaded);
                self::assertSame(['chocolatechip' => 'token'], $loaded->cookies);
                continue;
            }
            self::assertNull($store->get());
        }
    }

    private function cache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            public array $values = [];

            public int|null|\DateInterval $ttl = null;

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->values[$key] = $value;
                $this->ttl = $ttl;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->values[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->values = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $values = [];
                foreach ($keys as $key) {
                    $values[$key] = $this->get((string) $key, $default);
                }

                return $values;
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set((string) $key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete((string) $key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }
        };
    }
}
