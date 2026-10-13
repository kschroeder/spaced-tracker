<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Support;

use SpacedTracker\Auth\CookieAuthenticator;
use SpacedTracker\Auth\Credentials;
use SpacedTracker\Auth\InMemorySessionStore;
use SpacedTracker\Auth\SessionStoreInterface;
use SpacedTracker\Config\ClientConfig;
use SpacedTracker\Http\ConcurrentClientInterface;
use SpacedTracker\Http\SequentialConcurrentClient;
use SpacedTracker\Log\RedactingLogger;
use SpacedTracker\Query\PathCompiler;
use SpacedTracker\Query\ResultDecoder;
use SpacedTracker\RateLimit\SlidingWindowRateLimiter;
use SpacedTracker\RateLimit\UsageAdvisor;
use SpacedTracker\SpaceTrackClient;
use SpacedTracker\Testing\AdvancingSleeper;
use SpacedTracker\Testing\ArrayLogger;
use SpacedTracker\Testing\FrozenClock;
use SpacedTracker\Testing\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

final class Factory
{
    public static function clock(): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    public static function response(int $status = 200, string $body = '', array $headers = []): ResponseInterface
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse($status)->withBody($factory->createStream($body));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public static function loginResponse(): ResponseInterface
    {
        return self::response(200, '', [
            'Set-Cookie' => 'chocolatechip=session-token; Path=/; HttpOnly; Max-Age=7200',
        ]);
    }

    /**
     * @param list<ResponseInterface|callable(\Psr\Http\Message\RequestInterface): ResponseInterface> $responses
     * @return array{client: SpaceTrackClient, http: MockHttpClient, logger: ArrayLogger, clock: FrozenClock}
     */
    public static function client(
        array $responses,
        bool $enforceAdvisory = false,
        int $perMinute = 29,
        ?ClientConfig $config = null,
        ?ConcurrentClientInterface $concurrent = null,
        ?SessionStoreInterface $sessions = null,
    ): array {
        $http = new MockHttpClient($responses);
        $logger = new ArrayLogger();
        $clock = self::clock();
        $factory = new Psr17Factory();
        $config ??= new ClientConfig(enforceAdvisoryLimits: $enforceAdvisory);
        $client = new SpaceTrackClient(
            new Credentials('ada@example.com', 'correct-horse-battery'),
            $http,
            $factory,
            $factory,
            $clock,
            new RedactingLogger($logger),
            new SlidingWindowRateLimiter($clock, new AdvancingSleeper($clock), $logger, $perMinute, 299),
            new UsageAdvisor($clock, $logger, $config->enforceAdvisoryLimits),
            $concurrent ?? new SequentialConcurrentClient($http),
            $sessions ?? new InMemorySessionStore(),
            new CookieAuthenticator($http, $factory, $factory, $clock, $config),
            $config,
            new PathCompiler(),
            new ResultDecoder(),
        );

        return [
            'client' => $client,
            'http' => $http,
            'logger' => $logger,
            'clock' => $clock,
        ];
    }
}
