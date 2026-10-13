<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Integration;

use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Clock\SystemClock;
use SpacedTracker\Exception\ConfigurationException;
use SpacedTracker\Integration\Psr11ClientFactory;
use SpacedTracker\Query\Query;
use SpacedTracker\SpaceTrackClientInterface;
use SpacedTracker\Testing\ArrayLogger;
use SpacedTracker\Testing\FrozenClock;
use SpacedTracker\Testing\MockHttpClient;
use SpacedTracker\Tests\Support\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

final class Psr11ClientFactoryTest extends TestCase
{
    /** @var list<string> */
    private const ENV_KEYS = [
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

    /** @var array<string, string|null> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $value = getenv($key);
            $this->previous[$key] = $value === false ? null : $value;
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $value);
        }
    }

    public function testContainerServicesOverrideTheEnvironment(): void
    {
        $http = new MockHttpClient([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544}]'),
        ]);
        $logger = new ArrayLogger();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $requests = new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return (new Psr17Factory())->createRequest($method, $uri)->withHeader('X-Factory', 'container');
            }
        };
        $factory = new Psr17Factory();
        $client = (new Psr11ClientFactory())($this->container([
            'config' => [
                'spaced-tracker' => [
                    'identity' => 'ada@example.com',
                    'password' => 'correct-horse-battery',
                    'concurrency' => 'sequential',
                ],
            ],
            LoggerInterface::class => $logger,
            \Psr\Http\Client\ClientInterface::class => $http,
            RequestFactoryInterface::class => $requests,
            \Psr\Http\Message\StreamFactoryInterface::class => $factory,
            \Psr\Http\Message\ResponseFactoryInterface::class => $factory,
            \Psr\Clock\ClockInterface::class => $clock,
        ]));

        $result = $client->execute(Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1));

        self::assertSame(25544, $result->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertSame('container', $http->requests[1]->getHeaderLine('X-Factory'));
        self::assertFalse($logger->contains('correct-horse-battery'));
        self::assertEquals(new \DateTimeImmutable('2026-01-01T02:00:00Z'), $client->sessionExpiresAt());
    }

    public function testMissingServicesFallBackToTheEnvironment(): void
    {
        putenv('SPACETRACK_IDENTITY=ada@example.com');
        putenv('SPACETRACK_PASSWORD=correct-horse-battery');
        putenv('SPACETRACK_CONCURRENCY=sequential');

        $client = (new Psr11ClientFactory())($this->container([
            'config' => 'not-an-array',
            LoggerInterface::class => 'not-a-logger',
        ]));

        self::assertInstanceOf(SpaceTrackClientInterface::class, $client);
        $clock = (new \ReflectionProperty($client, 'clock'))->getValue($client);
        self::assertInstanceOf(SystemClock::class, $clock);
    }

    public function testConfigWithoutAPackageKeyIsIgnored(): void
    {
        putenv('SPACETRACK_IDENTITY=ada@example.com');
        putenv('SPACETRACK_PASSWORD=correct-horse-battery');
        putenv('SPACETRACK_CONCURRENCY=sequential');

        $ignored = (new Psr11ClientFactory())($this->container([
            'config' => ['other' => ['identity' => 'ada@example.com']],
        ]));
        $notAnArray = (new Psr11ClientFactory())($this->container([
            'config' => ['spaced-tracker' => 'nope'],
        ]));

        self::assertInstanceOf(SpaceTrackClientInterface::class, $ignored);
        self::assertInstanceOf(SpaceTrackClientInterface::class, $notAnArray);
    }

    public function testAContainerWithoutCredentialsCannotBuildAClient(): void
    {
        $this->expectException(ConfigurationException::class);
        (new Psr11ClientFactory())($this->container([]));
    }

    /**
     * @param array<string, mixed> $services
     */
    private function container(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /**
             * @param array<string, mixed> $services
             */
            public function __construct(private array $services)
            {
            }

            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new \RuntimeException('Missing service ' . $id);
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }
}
