<?php

declare(strict_types=1);

namespace SpacedTracker;

use GuzzleHttp\Client as GuzzleClient;
use SpacedTracker\Auth\CookieAuthenticator;
use SpacedTracker\Auth\Credentials;
use SpacedTracker\Auth\InMemorySessionStore;
use SpacedTracker\Auth\Psr16SessionStore;
use SpacedTracker\Auth\SessionStoreInterface;
use SpacedTracker\Clock\SystemClock;
use SpacedTracker\Config\ClientConfig;
use SpacedTracker\Config\EnvironmentConfig;
use SpacedTracker\Exception\ConfigurationException;
use SpacedTracker\Http\AmpConcurrentClient;
use SpacedTracker\Http\ConcurrentClientInterface;
use SpacedTracker\Http\CurlMultiConcurrentClient;
use SpacedTracker\Http\GuzzleConcurrentClient;
use SpacedTracker\Http\SequentialConcurrentClient;
use SpacedTracker\Http\SymfonyConcurrentClient;
use SpacedTracker\Log\RedactingLogger;
use SpacedTracker\RateLimit\NativeSleeper;
use SpacedTracker\RateLimit\RateLimiterInterface;
use SpacedTracker\RateLimit\SlidingWindowRateLimiter;
use SpacedTracker\RateLimit\UsageAdvisor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Assembles a client for plain PHP, a container, or a framework package.
 *
 * Pass a PSR-18 client when the application already has one. Otherwise the
 * builder uses symfony/http-client or guzzlehttp/guzzle when either is installed.
 */
final class ClientBuilder
{
    private ?ClientInterface $http = null;

    private ?RequestFactoryInterface $requestFactory = null;

    private ?StreamFactoryInterface $streamFactory = null;

    private ?ResponseFactoryInterface $responseFactory = null;

    private ?LoggerInterface $logger = null;

    private ?ClockInterface $clock = null;

    private ?ConcurrentClientInterface $concurrent = null;

    private ?SessionStoreInterface $sessions = null;

    private ?RateLimiterInterface $rateLimiter = null;

    private ?CacheInterface $cache = null;

    private ?object $symfony = null;

    public function __construct(
        private readonly Credentials $credentials,
        private readonly ClientConfig $config = new ClientConfig(),
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function configure(array $config): self
    {
        $identity = self::stringValue($config, 'identity');
        $password = self::stringValue($config, 'password');
        if ($identity === '' || $password === '') {
            throw new ConfigurationException('Space-Track identity and password are required.');
        }

        $clientConfig = new ClientConfig(
            baseUrl: self::stringValue($config, 'base_url', 'https://www.space-track.org'),
            timeoutSeconds: self::floatValue($config, 'timeout', 60.0),
            verifyTls: self::boolValue($config, 'verify_tls', true),
            ratePerMinute: self::intValue($config, 'rate_per_minute', 29),
            ratePerHour: self::intValue($config, 'rate_per_hour', 299),
            logPredicateValues: self::boolValue($config, 'log_predicate_values', false),
            sessionTtlSeconds: self::intValue($config, 'session_ttl', 7200),
            userAgent: self::stringValue($config, 'user_agent', 'kschroeder-spaced-tracker/1.0'),
            enforceAdvisoryLimits: self::boolValue($config, 'enforce_advisory_limits', false),
            concurrency: self::stringValue($config, 'concurrency', 'auto'),
        );

        return new self(new Credentials($identity, $password), $clientConfig);
    }

    /**
     * @param array<string, string>|null $env
     */
    public static function fromEnvironment(?array $env = null): self
    {
        return self::configure(EnvironmentConfig::read($env));
    }

    public function withHttpClient(ClientInterface $http): self
    {
        $clone = clone $this;
        $clone->http = $http;

        return $clone;
    }

    public function withRequestFactory(RequestFactoryInterface $factory): self
    {
        $clone = clone $this;
        $clone->requestFactory = $factory;

        return $clone;
    }

    public function withStreamFactory(StreamFactoryInterface $factory): self
    {
        $clone = clone $this;
        $clone->streamFactory = $factory;

        return $clone;
    }

    public function withResponseFactory(ResponseFactoryInterface $factory): self
    {
        $clone = clone $this;
        $clone->responseFactory = $factory;

        return $clone;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $clone = clone $this;
        $clone->logger = $logger;

        return $clone;
    }

    public function withClock(ClockInterface $clock): self
    {
        $clone = clone $this;
        $clone->clock = $clock;

        return $clone;
    }

    public function withConcurrentClient(ConcurrentClientInterface $concurrent): self
    {
        $clone = clone $this;
        $clone->concurrent = $concurrent;

        return $clone;
    }

    public function withSessionStore(SessionStoreInterface $sessions): self
    {
        $clone = clone $this;
        $clone->sessions = $sessions;

        return $clone;
    }

    public function withRateLimiter(RateLimiterInterface $rateLimiter): self
    {
        $clone = clone $this;
        $clone->rateLimiter = $rateLimiter;

        return $clone;
    }

    /**
     * Keep the Space-Track session in this cache, and reuse successful queries.
     *
     * The session entry uses the session lifetime. Queries are stored with a
     * null TTL, so they live for the cache default. An explicit session store
     * still wins for the session.
     */
    public function withCache(CacheInterface $cache): self
    {
        $clone = clone $this;
        $clone->cache = $cache;

        return $clone;
    }

    public function build(): SpaceTrackClient
    {
        $logger = new RedactingLogger($this->logger ?? new NullLogger());
        $clock = $this->clock ?? new SystemClock();
        [$requests, $streams, $responses] = $this->factories();
        $http = $this->http ?? $this->defaultHttp($responses, $streams);
        $concurrent = $this->concurrent ?? $this->defaultConcurrent($http, $responses, $streams);
        if ($this->sessions instanceof SessionStoreInterface) {
            $sessions = $this->sessions;
        } elseif ($this->cache instanceof CacheInterface) {
            $sessions = new Psr16SessionStore($this->cache);
        } else {
            $sessions = new InMemorySessionStore();
        }
        $limiter = $this->rateLimiter ?? new SlidingWindowRateLimiter(
            $clock,
            new NativeSleeper(),
            $logger,
            $this->config->ratePerMinute,
            $this->config->ratePerHour,
        );

        return new SpaceTrackClient(
            $this->credentials,
            $http,
            $requests,
            $streams,
            $clock,
            $logger,
            $limiter,
            new UsageAdvisor($clock, $logger, $this->config->enforceAdvisoryLimits),
            $concurrent,
            $sessions,
            new CookieAuthenticator($http, $requests, $streams, $clock, $this->config),
            $this->config,
            cache: $this->cache,
        );
    }

    /**
     * @return array{0: RequestFactoryInterface, 1: StreamFactoryInterface, 2: ResponseFactoryInterface}
     */
    private function factories(): array
    {
        if (
            $this->requestFactory instanceof RequestFactoryInterface
            && $this->streamFactory instanceof StreamFactoryInterface
            && $this->responseFactory instanceof ResponseFactoryInterface
        ) {
            return [$this->requestFactory, $this->streamFactory, $this->responseFactory];
        }

        if (!class_exists(Psr17Factory::class)) {
            throw new ConfigurationException(
                'Pass PSR-17 request, stream, and response factories, or install nyholm/psr7.',
            );
        }

        $factory = new Psr17Factory();

        return [
            $this->requestFactory ?? $factory,
            $this->streamFactory ?? $factory,
            $this->responseFactory ?? $factory,
        ];
    }

    private function defaultHttp(ResponseFactoryInterface $responses, StreamFactoryInterface $streams): ClientInterface
    {
        if (class_exists(HttpClient::class) && class_exists(Psr18Client::class)) {
            $this->symfony = HttpClient::create([
                'timeout' => $this->config->timeoutSeconds,
                'verify_peer' => $this->config->verifyTls,
                'verify_host' => $this->config->verifyTls,
                'headers' => ['User-Agent' => $this->config->userAgent],
            ]);

            return new Psr18Client($this->symfony, $responses, $streams);
        }

        if (class_exists(GuzzleClient::class)) {
            return new GuzzleClient([
                'timeout' => $this->config->timeoutSeconds,
                'verify' => $this->config->verifyTls,
                'http_errors' => false,
                'headers' => ['User-Agent' => $this->config->userAgent],
            ]);
        }

        throw new ConfigurationException(
            'Pass a PSR-18 client, or install symfony/http-client or guzzlehttp/guzzle.',
        );
    }

    private function defaultConcurrent(
        ClientInterface $http,
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
    ): ConcurrentClientInterface {
        $strategy = $this->config->concurrency;

        if ($strategy === 'sequential') {
            return new SequentialConcurrentClient($http);
        }

        if ($strategy === 'curl') {
            return new CurlMultiConcurrentClient($responses, $streams, $this->config->timeoutSeconds, $this->config->verifyTls);
        }

        if ($strategy === 'symfony') {
            if (!$this->symfonyClient() instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
                throw new ConfigurationException('Symfony concurrency requires symfony/http-client and no custom PSR-18 client.');
            }

            return new SymfonyConcurrentClient($this->symfonyClient(), $responses, $streams);
        }

        if ($strategy === 'guzzle') {
            if (!class_exists(GuzzleClient::class) || !$http instanceof GuzzleClient) {
                throw new ConfigurationException('Guzzle concurrency requires the HTTP client to be guzzlehttp/guzzle.');
            }

            return new GuzzleConcurrentClient($http);
        }

        if ($strategy === 'amp') {
            if (!class_exists(\Amp\Http\Client\HttpClientBuilder::class)) {
                throw new ConfigurationException('Amp concurrency requires amphp/http-client.');
            }

            return new AmpConcurrentClient(\Amp\Http\Client\HttpClientBuilder::buildDefault(), $responses, $streams);
        }

        $symfony = $this->symfonyClient();
        if ($symfony instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
            return new SymfonyConcurrentClient($symfony, $responses, $streams);
        }
        if (class_exists(GuzzleClient::class) && $http instanceof GuzzleClient) {
            return new GuzzleConcurrentClient($http);
        }

        return new SequentialConcurrentClient($http);
    }

    private function symfonyClient(): ?object
    {
        if ($this->symfony === null || !interface_exists(\Symfony\Contracts\HttpClient\HttpClientInterface::class)) {
            return null;
        }

        return $this->symfony instanceof \Symfony\Contracts\HttpClient\HttpClientInterface ? $this->symfony : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringValue(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;
        if (!is_string($value) && !is_numeric($value)) {
            throw new ConfigurationException(sprintf('Configuration "%s" must be a string.', $key));
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intValue(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        $numericString = is_string($value) && is_numeric($value);
        if (!is_int($value) && !$numericString && !is_float($value)) {
            throw new ConfigurationException(sprintf('Configuration "%s" must be an integer.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function floatValue(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new ConfigurationException(sprintf('Configuration "%s" must be a number.', $key));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function boolValue(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_int($value)) {
            return $value === 1;
        }

        throw new ConfigurationException(sprintf('Configuration "%s" must be a boolean.', $key));
    }
}
