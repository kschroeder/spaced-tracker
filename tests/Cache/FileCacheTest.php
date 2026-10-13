<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Cache;

use SpacedTracker\Auth\Psr16SessionStore;
use SpacedTracker\Auth\Session;
use SpacedTracker\Cache\CacheException;
use SpacedTracker\Cache\FileCache;
use SpacedTracker\Cache\InvalidArgumentException;
use SpacedTracker\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $directory;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/st-file-cache-' . bin2hex(random_bytes(4));
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-09T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testTheDefaultTtlIsSixtySeconds(): void
    {
        $cache = new FileCache($this->directory, clock: $this->clock);

        self::assertSame(60, FileCache::DEFAULT_TTL_SECONDS);
        self::assertSame(60, $cache->defaultTtlSeconds());
        self::assertSame(0700, fileperms($this->directory) & 0777);
        self::assertTrue($cache->set('row', ['id' => 1]));

        $path = $this->path('row');
        $raw = (string) file_get_contents($path);
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertStringStartsWith('{', $raw);
        self::assertStringNotContainsString('O:', $raw);
        self::assertStringNotContainsString('correct-horse-battery', $raw);

        $this->clock->advance(59);
        self::assertSame(['id' => 1], $cache->get('row'));
        $this->clock->advance(1);
        self::assertNull($cache->get('row'));
        self::assertFileDoesNotExist($path);
    }

    public function testAnExplicitTtlOverridesTheDefault(): void
    {
        $cache = $this->cache();
        $cache->set('row', 'kept', 5);

        $this->clock->advance(4);
        self::assertTrue($cache->has('row'));
        $this->clock->advance(1);
        self::assertFalse($cache->has('row'));
        self::assertSame('miss', $cache->get('row', 'miss'));
    }

    public function testZeroNegativeAndInvertedTtlsDeleteTheKey(): void
    {
        $cache = $this->cache();
        $cache->set('row', 'kept', 30);
        self::assertTrue($cache->set('row', 'gone', 0));
        self::assertFalse($cache->has('row'));

        $cache->set('row', 'kept', 30);
        self::assertTrue($cache->set('row', 'gone', -5));
        self::assertFalse($cache->has('row'));

        $cache->set('row', 'kept', 30);
        $interval = new \DateInterval('PT5S');
        $interval->invert = 1;
        self::assertTrue($cache->set('row', 'gone', $interval));
        self::assertSame('miss', $cache->get('row', 'miss'));
    }

    public function testADateIntervalSetsTheLifetime(): void
    {
        $cache = $this->cache();
        self::assertTrue($cache->set('row', 'kept', new \DateInterval('PT8S')));

        $this->clock->advance(7);
        self::assertSame('kept', $cache->get('row'));
        $this->clock->advance(1);
        self::assertFalse($cache->has('row'));
    }

    public function testNullAndFalseAreStored(): void
    {
        $cache = $this->cache();
        self::assertTrue($cache->set('empty', null, 30));
        self::assertTrue($cache->set('flag', false, 30));

        self::assertTrue($cache->has('empty'));
        self::assertNull($cache->get('empty', 'default'));
        self::assertTrue($cache->has('flag'));
        self::assertFalse($cache->get('flag', true));
    }

    public function testValuesThatAreNotJsonAreRejectedWithoutDroppingAnExistingEntry(): void
    {
        $cache = $this->cache();
        $cache->set('row', 'kept', 30);

        self::assertFalse($cache->set('bin', "\xFF\xFE", 30));
        self::assertFalse($cache->set('nan', \NAN, 30));
        self::assertFalse($cache->has('bin'));
        self::assertSame('kept', $cache->get('row'));
    }

    public function testInvalidKeysThrow(): void
    {
        $cache = $this->cache();
        foreach (['', 'a/b', 'a:b', 'a@b', 'has space', '{a}', "a\\b", str_repeat('a', 251)] as $key) {
            try {
                $cache->get($key);
                self::fail('Key must be rejected: ' . $key);
            } catch (InvalidArgumentException $exception) {
                self::assertInstanceOf(\Psr\SimpleCache\InvalidArgumentException::class, $exception);
            }
        }

        try {
            $cache->getMultiple(['ok', 1]);
            self::fail('A non-string key must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertInstanceOf(\Psr\SimpleCache\InvalidArgumentException::class, $exception);
        }
    }

    public function testMultipleOperationsValidateBeforeWriting(): void
    {
        $cache = $this->cache();
        $cache->set('kept', 'old', 30);

        try {
            $cache->setMultiple(['kept' => 'new', 'bad key' => 'x']);
            self::fail('A bad key must be rejected before any value is replaced.');
        } catch (InvalidArgumentException) {
            self::assertSame('old', $cache->get('kept'));
        }

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 'two'], 30));
        self::assertSame(
            ['a' => 1, 'b' => 'two', 'c' => 'no'],
            $cache->getMultiple(['a', 'b', 'c'], 'no'),
        );
        self::assertTrue($cache->deleteMultiple(['a', 'missing']));
        self::assertFalse($cache->has('a'));
        self::assertTrue($cache->has('b'));
        self::assertTrue($cache->delete('missing'));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('b'));
        self::assertDirectoryExists($this->directory);
    }

    public function testMalformedFilesAreMisses(): void
    {
        $cache = $this->cache();
        $path = $this->path('row');
        file_put_contents($path, 'not-json');

        self::assertFalse($cache->has('row'));
        self::assertFileDoesNotExist($path);
        self::assertSame('miss', $cache->get('row', 'miss'));
    }

    public function testTheSessionStoreKeyRoundTrips(): void
    {
        $cache = $this->cache();
        $now = $this->clock->now();
        $store = new Psr16SessionStore($cache);
        $store->put(new Session(
            ['chocolatechip' => 'session-token'],
            $now,
            $now->modify('+2 hours'),
        ));

        $loaded = $store->get();

        self::assertInstanceOf(Session::class, $loaded);
        self::assertSame('session-token', $loaded->cookies['chocolatechip'] ?? null);
        self::assertSame('chocolatechip=session-token', $loaded->headerValue());
    }

    public function testConstructorRejectsAnUnusableDirectoryOrTtl(): void
    {
        foreach (['' => 'empty', "bad\0dir" => 'empty'] as $directory => $needle) {
            try {
                new FileCache((string) $directory, 60);
                self::fail('Directory must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($needle, strtolower($exception->getMessage()));
            }
        }

        try {
            new FileCache($this->directory, 0);
            self::fail('A zero default TTL must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('TTL', $exception->getMessage());
        }

        file_put_contents($this->directory, 'not-a-directory');
        $this->expectException(CacheException::class);
        new FileCache($this->directory, 60);
    }

    private function cache(): FileCache
    {
        return new FileCache($this->directory, 60, $this->clock);
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
