<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Cache;

use SpacedTracker\Auth\InMemorySessionStore;
use SpacedTracker\Cache\FileCache;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\ClientBuilder;
use SpacedTracker\Exception\QueryException;
use SpacedTracker\Query\Query;
use SpacedTracker\SpaceTrackClient;
use SpacedTracker\Testing\ArrayLogger;
use SpacedTracker\Testing\FrozenClock;
use SpacedTracker\Testing\MockHttpClient;
use SpacedTracker\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

final class ClientCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/st-client-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testQueriesUseTheCacheDefaultAndTheSessionKeepsItsOwnLifetime(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('now'));
        $cache = new FileCache($this->directory, clock: $clock);
        $logger = new ArrayLogger();
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544,"OBJECT_NAME":"ISS"}]'),
            Factory::response(200, '[{"NORAD_CAT_ID":25544,"OBJECT_NAME":"ISS (ZARYA)"}]'),
        ]);
        $client = $this->client($http, $clock, $cache, $logger);
        $query = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1);

        $first = $client->execute($query);
        $second = $client->execute($query);

        self::assertSame(25544, $first->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertSame('ISS', $second->records[0]['OBJECT_NAME'] ?? null);
        self::assertSame(0.0, $second->durationSeconds);
        self::assertCount(2, $http->requests);
        self::assertTrue($logger->contains('spacetrack.cache.hit'));
        $this->assertLifetimes($clock, 60);

        $clock->advance(59);
        $client->execute($query);
        self::assertCount(2, $http->requests);

        $clock->advance(2);
        $third = $client->execute($query);

        self::assertSame('ISS (ZARYA)', $third->records[0]['OBJECT_NAME'] ?? null);
        self::assertCount(3, $http->requests);
        self::assertStringNotContainsString('ajaxauth/login', (string) $http->requests[2]->getUri());
        self::assertStringNotContainsString('correct-horse-battery', $this->cacheText());
    }

    public function testAShorterCacheDefaultExpiresTheQueryEarlier(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('now'));
        $cache = new FileCache($this->directory, 15, $clock);
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544,"OBJECT_NAME":"ISS"}]'),
            Factory::response(200, '[{"NORAD_CAT_ID":25544,"OBJECT_NAME":"ISS (ZARYA)"}]'),
        ]);
        $client = $this->client($http, $clock, $cache, new ArrayLogger());
        $query = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1);

        $client->execute($query);
        $clock->advance(14);
        $client->execute($query);
        self::assertCount(2, $http->requests);

        $clock->advance(2);
        $fresh = $client->execute($query);

        self::assertSame('ISS (ZARYA)', $fresh->records[0]['OBJECT_NAME'] ?? null);
        self::assertCount(3, $http->requests);
    }

    public function testASecondClientReusesTheCachedSession(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('now'));
        $cache = new FileCache($this->directory, 60, $clock);
        $firstHttp = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544}]'),
        ]);
        $first = $this->client($firstHttp, $clock, $cache, new ArrayLogger());
        $first->execute(Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1));

        $secondHttp = new MockHttpClient([
            Factory::response(200, '[]'),
        ]);
        $second = $this->client($secondHttp, $clock, $cache, new ArrayLogger());
        $result = $second->execute(Query::create(RequestClass::Boxscore)->limit(1));

        self::assertSame([], $result->records);
        self::assertCount(1, $secondHttp->requests);
        self::assertStringContainsString('chocolatechip=session-token', $secondHttp->requests[0]->getHeaderLine('Cookie'));
        self::assertStringNotContainsString('ajaxauth/login', (string) $secondHttp->requests[0]->getUri());
    }

    public function testExecuteManySkipsQueriesAlreadyInTheCache(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('now'));
        $cache = new FileCache($this->directory, 60, $clock);
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544,"OBJECT_NAME":"ISS"}]'),
            Factory::response(200, '[{"NORAD_CAT_ID":20580,"OBJECT_NAME":"HST"}]'),
            Factory::response(200, '[{"NORAD_CAT_ID":48274,"OBJECT_NAME":"CSS"}]'),
        ]);
        $client = $this->client($http, $clock, $cache, new ArrayLogger());
        $iss = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1);
        $hst = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 20580)->limit(1);
        $station = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 48274)->limit(1);

        $client->executeMany(['iss' => $iss, 'hst' => $hst]);
        self::assertCount(3, $http->requests);

        $second = $client->executeMany(['iss' => $iss, 'hst' => $hst, 'station' => $station]);

        self::assertSame('ISS', $second['iss']->records[0]['OBJECT_NAME'] ?? null);
        self::assertSame(0.0, $second['iss']->durationSeconds);
        self::assertSame('HST', $second['hst']->records[0]['OBJECT_NAME'] ?? null);
        self::assertSame('CSS', $second['station']->records[0]['OBJECT_NAME'] ?? null);
        self::assertSame(['iss', 'hst', 'station'], array_keys($second));
        self::assertCount(4, $http->requests);
        self::assertStringNotContainsString('ajaxauth/login', (string) $http->requests[3]->getUri());
    }

    public function testAnExplicitSessionStoreIsNotReplacedByTheCache(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('now'));
        $cache = new FileCache($this->directory, 60, $clock);
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544,"OBJECT_NAME":"ISS"}]'),
        ]);
        $client = $this->client($http, $clock, $cache, new ArrayLogger(), new InMemorySessionStore());
        $query = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1);
        $client->execute($query);
        $client->execute($query);

        self::assertCount(2, $http->requests);
        self::assertStringNotContainsString('chocolatechip', $this->cacheText());
        self::assertStringContainsString('ISS', $this->cacheText());

        $secondHttp = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[]'),
        ]);
        $second = $this->client($secondHttp, $clock, $cache, new ArrayLogger(), new InMemorySessionStore());
        $second->execute(Query::create(RequestClass::Boxscore)->limit(1));
        $cached = $second->execute($query);

        self::assertSame('ISS', $cached->records[0]['OBJECT_NAME'] ?? null);
        self::assertCount(2, $secondHttp->requests);
        self::assertStringContainsString('ajaxauth/login', (string) $secondHttp->requests[0]->getUri());
    }

    public function testFailedQueriesAreNotCached(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('now'));
        $cache = new FileCache($this->directory, 60, $clock);
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(503, 'unavailable'),
            Factory::response(503, 'unavailable'),
        ]);
        $client = $this->client($http, $clock, $cache, new ArrayLogger());
        $query = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1);

        try {
            $client->execute($query);
            self::fail('A failed query must throw.');
        } catch (QueryException) {
            self::assertCount(2, $http->requests);
        }
        try {
            $client->execute($query);
            self::fail('A failed query must be requested again.');
        } catch (QueryException) {
            self::assertCount(3, $http->requests);
        }
    }

    public function testTheClientDoesNotCacheUnlessACacheIsConfigured(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('now'));
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544}]'),
            Factory::response(200, '[{"NORAD_CAT_ID":25544}]'),
        ]);
        $client = $this->client($http, $clock, null, new ArrayLogger());
        $query = Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1);

        $client->execute($query);
        $client->execute($query);

        self::assertCount(3, $http->requests);
        self::assertDirectoryDoesNotExist($this->directory);
    }

    private function client(
        MockHttpClient $http,
        FrozenClock $clock,
        ?FileCache $cache,
        ArrayLogger $logger,
        ?InMemorySessionStore $sessions = null,
    ): SpaceTrackClient {
        $builder = ClientBuilder::configure([
            'identity' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'base_url' => 'https://example.test',
            'concurrency' => 'sequential',
        ])->withHttpClient($http)->withClock($clock)->withLogger($logger);
        if ($cache instanceof FileCache) {
            $builder = $builder->withCache($cache);
        }
        if ($sessions instanceof InMemorySessionStore) {
            $builder = $builder->withSessionStore($sessions);
        }

        return $builder->build();
    }

    private function assertLifetimes(FrozenClock $clock, int $queryTtl): void
    {
        $now = $clock->now()->getTimestamp();
        $remaining = [];
        foreach ($this->jsonFiles() as $decoded) {
            $expiresAt = $decoded['expires_at'] ?? null;
            self::assertIsInt($expiresAt);
            $remaining[] = $expiresAt - $now;
        }
        sort($remaining);

        self::assertCount(2, $remaining);
        self::assertSame($queryTtl, $remaining[0]);
        self::assertGreaterThan($queryTtl, $remaining[1]);
    }

    private function cacheText(): string
    {
        $text = '';
        foreach ($this->jsonFiles() as $decoded) {
            $encoded = json_encode($decoded);
            $text .= is_string($encoded) ? $encoded : '';
        }

        return $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonFiles(): array
    {
        self::assertDirectoryExists($this->directory);
        $decodedFiles = [];
        $entries = scandir($this->directory);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if (preg_match('/^[a-f0-9]{64}\.json$/', $entry) !== 1) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($this->directory . '/' . $entry), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $decodedFiles[] = $decoded;
        }

        return $decodedFiles;
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
