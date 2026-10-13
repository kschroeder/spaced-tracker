<?php

declare(strict_types=1);

namespace SpacedTracker\Tests;

use SpacedTracker\Auth\InMemorySessionStore;
use SpacedTracker\Auth\Session;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\ClientBuilder;
use SpacedTracker\Http\ConcurrentClientInterface;
use SpacedTracker\RateLimit\RateLimiterInterface;
use SpacedTracker\Clock\SystemClock;
use SpacedTracker\Config\ClientConfig;
use SpacedTracker\Exception\ConfigurationException;
use SpacedTracker\Http\AmpConcurrentClient;
use SpacedTracker\Http\CurlMultiConcurrentClient;
use SpacedTracker\Http\GuzzleConcurrentClient;
use SpacedTracker\Http\SequentialConcurrentClient;
use SpacedTracker\Http\SymfonyConcurrentClient;
use SpacedTracker\Query\Query;
use SpacedTracker\RateLimit\NativeSleeper;
use SpacedTracker\RateLimit\SlidingWindowRateLimiter;
use SpacedTracker\Testing\ArrayLogger;
use SpacedTracker\Testing\MockHttpClient;
use SpacedTracker\Tests\Support\Factory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

final class ClientBuilderTest extends TestCase
{
    public function testBuildsAClientThatQueriesThroughAnInjectedPsr18Client(): void
    {
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544,"OBJECT_NAME":"ISS (ZARYA)"}]'),
        ]);
        $logger = new ArrayLogger();
        $client = $this->builder()
            ->withHttpClient($http)
            ->withLogger($logger)
            ->withClock(Factory::clock())
            ->build();

        $result = $client->execute(Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1));

        self::assertSame(25544, $result->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertSame('chocolatechip=session-token', $http->requests[1]->getHeaderLine('Cookie'));
        self::assertFalse($logger->contains('correct-horse-battery'));
        self::assertTrue($client->hasSession());
    }

    public function testFluentMethodsCloneTheBuilder(): void
    {
        $base = $this->builder();
        $logger = new ArrayLogger();
        $quiet = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":20580}]'),
        ]);
        $noisy = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544}]'),
        ]);

        $withoutLogger = $base->withHttpClient($quiet)->withClock(Factory::clock())->build();
        $withLogger = $base->withLogger($logger)->withHttpClient($noisy)->withClock(Factory::clock())->build();
        $quietResult = $withoutLogger->execute(Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 20580)->limit(1));

        self::assertSame(20580, $quietResult->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertSame([], $logger->records);
        $withLogger->execute(Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1));
        self::assertNotSame([], $logger->records);
        self::assertFalse($logger->contains('correct-horse-battery'));
    }

    public function testInjectedFactoriesAreUsedAndMissingOnesComeFromNyholm(): void
    {
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[]'),
        ]);
        $requests = new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return (new Psr17Factory())->createRequest($method, $uri)->withHeader('X-Factory', 'custom');
            }
        };
        $client = $this->builder()
            ->withHttpClient($http)
            ->withRequestFactory($requests)
            ->withClock(Factory::clock())
            ->build();

        $client->execute(Query::create(RequestClass::Boxscore)->limit(1));

        self::assertSame('custom', $http->requests[1]->getHeaderLine('X-Factory'));
    }

    public function testAllThreeFactoriesCanBeInjectedTogether(): void
    {
        $factory = new Psr17Factory();
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[]'),
        ]);
        $client = $this->builder()
            ->withHttpClient($http)
            ->withRequestFactory($factory)
            ->withStreamFactory($factory)
            ->withResponseFactory($factory)
            ->withClock(Factory::clock())
            ->build();

        self::assertSame(200, $client->execute(Query::create(RequestClass::Boxscore)->limit(1))->statusCode);
    }

    public function testDefaultsUseTheSystemClockAndNativeSleeperWithoutCallingSpaceTrack(): void
    {
        $client = $this->builder(['concurrency' => 'sequential'])->withHttpClient(new MockHttpClient([]))->build();
        $clock = $this->property($client, 'clock');
        $limiter = $this->property($client, 'rateLimiter');

        self::assertInstanceOf(SystemClock::class, $clock);
        self::assertEqualsWithDelta(time(), $clock->now()->getTimestamp(), 2);
        self::assertInstanceOf(SlidingWindowRateLimiter::class, $limiter);
        $sleeper = $this->property($limiter, 'sleeper');
        self::assertInstanceOf(NativeSleeper::class, $sleeper);
        $sleeper->sleep(0);
        $sleeper->sleep(0.001);
    }

    public function testAutoConcurrencyStaysSequentialForACustomPsr18Client(): void
    {
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[]'),
        ]);
        $client = $this->builder(['concurrency' => 'auto'])->withHttpClient($http)->withClock(Factory::clock())->build();

        self::assertInstanceOf(SequentialConcurrentClient::class, $this->property($client, 'concurrent'));
        self::assertSame(200, $client->execute(Query::create(RequestClass::Boxscore)->limit(1))->statusCode);
    }

    public function testNamedStrategiesSelectTheMatchingConcurrentClient(): void
    {
        $curl = $this->builder(['concurrency' => 'curl'])->withHttpClient(new MockHttpClient([]))->build();
        $symfony = $this->builder(['concurrency' => 'symfony'])->withClock(Factory::clock())->build();
        $amp = $this->builder(['concurrency' => 'amp'])->withHttpClient(new MockHttpClient([]))->build();
        $automatic = $this->builder(['concurrency' => 'auto'])->build();

        self::assertInstanceOf(CurlMultiConcurrentClient::class, $this->property($curl, 'concurrent'));
        self::assertInstanceOf(SymfonyConcurrentClient::class, $this->property($symfony, 'concurrent'));
        self::assertInstanceOf(AmpConcurrentClient::class, $this->property($amp, 'concurrent'));
        self::assertInstanceOf(SymfonyConcurrentClient::class, $this->property($automatic, 'concurrent'));
    }

    public function testSymfonyAndGuzzleStrategiesRejectAForeignHttpClient(): void
    {
        $http = new MockHttpClient([]);
        try {
            $this->builder(['concurrency' => 'symfony'])->withHttpClient($http)->build();
            self::fail('Symfony concurrency must reject a custom PSR-18 client.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('Symfony concurrency', $exception->getMessage());
        }

        try {
            $this->builder(['concurrency' => 'guzzle'])->withHttpClient($http)->build();
            self::fail('Guzzle concurrency must reject a non-Guzzle client.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('Guzzle concurrency', $exception->getMessage());
        }
    }

    public function testGuzzleStrategySendsTheBatchThroughTheInjectedGuzzleClient(): void
    {
        $mock = new MockHandler([
            new GuzzleResponse(200, ['Set-Cookie' => 'chocolatechip=session-token; Max-Age=7200'], ''),
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], '[{"NORAD_CAT_ID":25544}]'),
        ]);
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $client = $this->builder(['concurrency' => 'guzzle'])->withHttpClient($guzzle)->withClock(Factory::clock())->build();

        self::assertInstanceOf(GuzzleConcurrentClient::class, $this->property($client, 'concurrent'));
        $result = $client->execute(Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1));

        self::assertSame(25544, $result->records[0]['NORAD_CAT_ID'] ?? null);
    }

    public function testAutoConcurrencyUsesGuzzleWhenThatClientIsInjected(): void
    {
        $mock = new MockHandler([
            new GuzzleResponse(200, ['Set-Cookie' => 'chocolatechip=session-token; Max-Age=7200'], ''),
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], '[{"NORAD_CAT_ID":25544}]'),
        ]);
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $client = $this->builder(['concurrency' => 'auto'])->withHttpClient($guzzle)->withClock(Factory::clock())->build();
        $many = $client->executeMany([
            'iss' => Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1),
        ]);

        self::assertInstanceOf(GuzzleConcurrentClient::class, $this->property($client, 'concurrent'));
        self::assertSame(25544, $many['iss']->records[0]['NORAD_CAT_ID'] ?? null);
    }

    public function testInjectedCollaboratorsReplaceTheDefaults(): void
    {
        $clock = Factory::clock();
        $sessions = new InMemorySessionStore();
        $sessions->put(new Session(
            ['chocolatechip' => 'stored-token'],
            $clock->now(),
            $clock->now()->modify('+2 hours'),
        ));
        $http = new MockHttpClient([
            Factory::response(200, '[{"NORAD_CAT_ID":25544}]'),
        ]);
        $limiter = new class implements RateLimiterInterface {
            public int $acquires = 0;

            public function acquire(): void
            {
                $this->acquires++;
            }
        };
        $concurrent = new class implements ConcurrentClientInterface {
            public int $batches = 0;

            public function send(array $requests): array
            {
                $this->batches++;
                $factory = new Psr17Factory();
                $responses = [];
                foreach ($requests as $key => $request) {
                    unset($request);
                    $responses[$key] = $factory->createResponse(200)->withBody($factory->createStream('[]'));
                }

                return $responses;
            }
        };
        $client = $this->builder()
            ->withHttpClient($http)
            ->withClock($clock)
            ->withSessionStore($sessions)
            ->withRateLimiter($limiter)
            ->withConcurrentClient($concurrent)
            ->build();

        $result = $client->execute(Query::create(RequestClass::Gp)->limit(1));
        $many = $client->executeMany([
            'box' => Query::create(RequestClass::Boxscore)->limit(1),
        ]);

        self::assertSame(25544, $result->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertSame('chocolatechip=stored-token', $http->requests[0]->getHeaderLine('Cookie'));
        self::assertCount(1, $http->requests);
        self::assertGreaterThan(0, $limiter->acquires);
        self::assertSame(1, $concurrent->batches);
        self::assertSame([], $many['box']->records);
    }

    public function testConfigureCoercesScalarValuesAndRejectsTheWrongTypes(): void
    {
        $client = $this->builder([
            'identity' => 100,
            'password' => 'correct-horse-battery',
            'timeout' => '15',
            'verify_tls' => 1,
            'log_predicate_values' => 'yes',
            'enforce_advisory_limits' => 0,
            'rate_per_minute' => '7',
            'rate_per_hour' => 40.0,
            'session_ttl' => '90',
            'user_agent' => 'catalog/2',
            'base_url' => 'https://example.test/',
            'concurrency' => 'sequential',
        ])->withHttpClient(new MockHttpClient([]))->withClock(Factory::clock())->build();
        $config = $this->property($client, 'config');

        self::assertInstanceOf(ClientConfig::class, $config);
        self::assertSame(15.0, $config->timeoutSeconds);
        self::assertTrue($config->verifyTls);
        self::assertTrue($config->logPredicateValues);
        self::assertFalse($config->enforceAdvisoryLimits);
        self::assertSame(7, $config->ratePerMinute);
        self::assertSame(40, $config->ratePerHour);
        self::assertSame(90, $config->sessionTtlSeconds);
        self::assertSame('catalog/2', $config->userAgent);
        self::assertSame('https://example.test', $config->normalizedBaseUrl());

        $intFalse = $this->builder(['verify_tls' => 2])->withHttpClient(new MockHttpClient([]))->build();
        self::assertFalse($this->property($intFalse, 'config')->verifyTls);

        $this->assertConfigureRejects([]);
        $this->assertConfigureRejects(['identity' => 'ada@example.com', 'password' => '']);
        $this->assertConfigureRejects(['identity' => 'ada@example.com', 'password' => 'secret', 'timeout' => ['slow']]);
        $this->assertConfigureRejects(['identity' => 'ada@example.com', 'password' => 'secret', 'rate_per_minute' => 'many']);
        $this->assertConfigureRejects(['identity' => 'ada@example.com', 'password' => 'secret', 'verify_tls' => 1.5]);
        $this->assertConfigureRejects(['identity' => 'ada@example.com', 'password' => 'secret', 'user_agent' => ['catalog']]);
    }

    public function testFromEnvironmentReadsTheSameKeysAsTheFrameworkPackages(): void
    {
        $client = ClientBuilder::fromEnvironment([
            'SPACETRACK_IDENTITY' => 'ada@example.com',
            'SPACETRACK_PASSWORD' => 'correct-horse-battery',
            'SPACETRACK_CONCURRENCY' => 'sequential',
            'SPACETRACK_TIMEOUT' => '12.5',
            'SPACETRACK_VERIFY_TLS' => 'no',
            'SPACETRACK_USER_AGENT' => 'catalog/env',
        ])->withHttpClient(new MockHttpClient([]))->withClock(Factory::clock())->build();
        $config = $this->property($client, 'config');

        self::assertSame(12.5, $config->timeoutSeconds);
        self::assertFalse($config->verifyTls);
        self::assertSame('catalog/env', $config->userAgent);
        self::assertSame('sequential', $config->concurrency);

        try {
            ClientBuilder::fromEnvironment([
                'SPACETRACK_IDENTITY' => 'ada@example.com',
                'SPACETRACK_PASSWORD' => 'correct-horse-battery',
                'SPACETRACK_SESSION_TTL' => '30',
            ]);
            self::fail('A session TTL below 60 seconds must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Session TTL', $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function builder(array $overrides = []): ClientBuilder
    {
        return ClientBuilder::configure($overrides + [
            'identity' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'concurrency' => 'sequential',
        ]);
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertConfigureRejects(array $config): void
    {
        try {
            ClientBuilder::configure($config);
            self::fail('Configuration must be rejected.');
        } catch (ConfigurationException | \InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
