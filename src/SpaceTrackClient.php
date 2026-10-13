<?php

declare(strict_types=1);

namespace SpacedTracker;

use SpacedTracker\Auth\AuthenticatorInterface;
use SpacedTracker\Auth\Credentials;
use SpacedTracker\Auth\Session;
use SpacedTracker\Auth\SessionStoreInterface;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Config\ClientConfig;
use SpacedTracker\Exception\DecodeException;
use SpacedTracker\Exception\QueryException;
use SpacedTracker\Exception\RateLimitException;
use SpacedTracker\Exception\TransportException;
use SpacedTracker\File\MultipartBody;
use SpacedTracker\Http\ConcurrentClientInterface;
use SpacedTracker\Http\HttpExchange;
use SpacedTracker\Log\Redactor;
use SpacedTracker\Query\PathCompiler;
use SpacedTracker\Query\Query;
use SpacedTracker\Query\QueryResult;
use SpacedTracker\Query\ResultDecoder;
use SpacedTracker\Query\Token;
use SpacedTracker\Query\Criterion;
use SpacedTracker\RateLimit\RateLimiterInterface;
use SpacedTracker\RateLimit\UsageAdvisor;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

final class SpaceTrackClient implements SpaceTrackClientInterface
{
    /** @var array<string, mixed> */
    private array $definitions = [];

    public function __construct(
        private readonly Credentials $credentials,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly UsageAdvisor $advisor,
        private readonly ConcurrentClientInterface $concurrent,
        private readonly SessionStoreInterface $sessions,
        private readonly AuthenticatorInterface $authenticator,
        private readonly ClientConfig $config,
        private readonly PathCompiler $compiler = new PathCompiler(),
        private readonly ResultDecoder $decoder = new ResultDecoder(),
        private readonly Redactor $redactor = new Redactor(),
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    public function login(): void
    {
        $this->rateLimiter->acquire();
        try {
            $session = $this->authenticator->login($this->credentials);
        } catch (\Throwable $exception) {
            $this->logger->error('Space-Track login failed.', [
                'event' => 'spacetrack.auth.failed',
                'identity' => $this->credentials->redactedIdentity(),
                'exception' => $exception::class,
            ]);
            throw $exception;
        }

        $this->sessions->put($session);
        $this->logger->info('Space-Track login succeeded.', [
            'event' => 'spacetrack.auth.login',
            'identity' => $this->credentials->redactedIdentity(),
            'expires_at' => $session->expiresAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function logout(): void
    {
        $session = $this->sessions->get();
        if ($session instanceof Session) {
            $this->authenticator->logout($session);
        }
        $this->sessions->clear();
        $this->logger->info('Space-Track session cleared.', [
            'event' => 'spacetrack.auth.logout',
            'identity' => $this->credentials->redactedIdentity(),
        ]);
    }

    public function hasSession(): bool
    {
        $session = $this->sessions->get();

        return $session instanceof Session && !$session->isExpired($this->clock);
    }

    public function sessionExpiresAt(): ?\DateTimeImmutable
    {
        return $this->sessions->get()?->expiresAt;
    }

    public function execute(Query $query): QueryResult
    {
        $path = $this->compiler->compile($query);
        $cached = $this->readCachedQuery($query, $path);
        if ($cached instanceof QueryResult) {
            return $cached;
        }

        $result = $this->decode($query, $this->dispatch('GET', $path, null, [], $query));

        return $this->storeCachedQuery($path, $result);
    }

    public function executeMany(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        $results = [];
        $pending = [];
        $paths = [];
        foreach ($queries as $key => $query) {
            $paths[$key] = $this->compiler->compile($query);
            $cached = $this->readCachedQuery($query, $paths[$key]);
            if ($cached instanceof QueryResult) {
                $results[$key] = $cached;
                continue;
            }
            $pending[$key] = $query;
        }
        if ($pending === []) {
            return $this->cachedBatch($queries, $results);
        }

        $session = $this->ensureSession();
        $requests = [];
        foreach ($pending as $key => $query) {
            $this->advisor->inspect($query);
            $this->rateLimiter->acquire();
            $path = $paths[$key];
            $requests[$key] = $this->createRequest('GET', $path, null, [], $session);
            $this->logRequest($query, 'GET', $path);
        }

        $started = hrtime(true);
        $responses = $this->concurrent->send($requests);
        $duration = (hrtime(true) - $started) / 1_000_000_000;

        $retry = [];
        foreach ($pending as $key => $query) {
            $response = $responses[$key] ?? null;
            if (!$response instanceof ResponseInterface) {
                throw new TransportException('The concurrent client dropped a Space-Track response.');
            }
            if ($response->getStatusCode() === 401) {
                $retry[$key] = $query;
                continue;
            }
            $results[$key] = $this->storeCachedQuery(
                $paths[$key],
                $this->finish($query, new HttpExchange($response, $duration)),
            );
        }

        if ($retry !== []) {
            $this->sessions->clear();
            $this->logger->info('Space-Track session expired during a batch. Logging in again.', [
                'event' => 'spacetrack.auth.refresh',
                'count' => count($retry),
            ]);
            foreach ($retry as $key => $query) {
                $results[$key] = $this->execute($query);
            }
        }

        return $this->cachedBatch($queries, $results);
    }

    public function modelDefinition(string $class, ?string $controller = null): array
    {
        [$class, $controller] = $this->resolveClass($class, $controller);
        $cacheKey = $controller . '.' . $class;
        $cached = $this->definitions[$cacheKey] ?? null;
        if (is_array($cached)) {
            return $cached;
        }

        $exchange = $this->dispatch('GET', $this->compiler->modelDefinitionPath($controller, $class), null, [], null);
        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $exchange->response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DecodeException('Space-Track model definition was not valid JSON.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new DecodeException('Space-Track model definition was not a JSON object or list.');
        }

        $this->definitions[$cacheKey] = $decoded;

        return $decoded;
    }

    public function uploadFile(string $folderId, string $filename, string $contents, string $contentType = 'application/octet-stream'): QueryResult
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $folderId) !== 1) {
            throw new \InvalidArgumentException('Invalid folder id.');
        }
        $safeName = str_replace(["\0", '/', '\\'], '', $filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            throw new \InvalidArgumentException('Invalid upload filename.');
        }

        $multipart = MultipartBody::singleFile('file', $safeName, $contents, $contentType);
        $path = sprintf('/fileshare/query/class/upload/folder_id/%s', rawurlencode($folderId));
        $exchange = $this->dispatch('POST', $path, $multipart['body'], [
            'Content-Type' => $multipart['contentType'],
        ], null);

        $query = Query::create(RequestClass::FileShareUpload)->showEmpty(false);
        try {
            return $this->decoder->decode(
                $query,
                $exchange->response->getStatusCode(),
                (string) $exchange->response->getBody(),
                $this->headerMap($exchange->response->getHeaders()),
                $exchange->durationSeconds,
            );
        } catch (DecodeException) {
            return new QueryResult(
                $query,
                $exchange->response->getStatusCode(),
                (string) $exchange->response->getBody(),
                [],
                null,
                $exchange->durationSeconds,
                $this->headerMap($exchange->response->getHeaders()),
            );
        }
    }

    public function sendAuthenticated(RequestInterface $request): ResponseInterface
    {
        $path = $this->pathFor($request);
        $body = (string) $request->getBody();
        $headers = $this->forwardableHeaders($request);
        $exchange = $this->dispatch($request->getMethod(), $path, $body === '' ? null : $body, $headers, null);

        return $exchange->response;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function dispatch(string $method, string $path, ?string $body, array $headers, ?Query $query, bool $retried = false): HttpExchange
    {
        if ($query instanceof Query) {
            $this->advisor->inspect($query);
        }
        $this->rateLimiter->acquire();
        $session = $this->ensureSession();
        $request = $this->createRequest($method, $path, $body, $headers, $session);
        $this->logRequest($query, $method, $path);

        $started = hrtime(true);
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            $recovered = $this->responseFromException($exception);
            if (!$recovered instanceof ResponseInterface) {
                $this->logger->error('Space-Track transport failed.', [
                    'event' => 'spacetrack.http.transport_error',
                    'method' => $method,
                    'class' => $query?->className(),
                    'exception' => $exception::class,
                ]);
                throw new TransportException('Space-Track request failed.', 0, $exception);
            }
            $response = $recovered;
        }
        $duration = (hrtime(true) - $started) / 1_000_000_000;

        if ($response->getStatusCode() === 401 && !$retried) {
            $this->sessions->clear();
            $this->logger->info('Space-Track session expired. Logging in again.', [
                'event' => 'spacetrack.auth.refresh',
            ]);

            return $this->dispatch($method, $path, $body, $headers, $query, true);
        }

        return $this->guard(new HttpExchange($response, $duration), $query, $method);
    }

    private function finish(Query $query, HttpExchange $exchange): QueryResult
    {
        $guarded = $this->guard($exchange, $query, 'GET');

        return $this->decode($query, $guarded);
    }

    private function guard(HttpExchange $exchange, ?Query $query, string $method): HttpExchange
    {
        $status = $exchange->response->getStatusCode();
        $this->logger->info('Space-Track response received.', [
            'event' => 'spacetrack.http.response',
            'method' => $method,
            'status' => $status,
            'bytes' => $exchange->response->getBody()->getSize(),
            'duration_seconds' => round($exchange->durationSeconds, 4),
            'class' => $query?->className(),
            'controller' => $query?->controller(),
            'rate_headers' => $this->rateHeaders($exchange->response),
        ]);

        if ($status === 429) {
            throw new RateLimitException('Space-Track rejected the request because the account rate limit was exceeded.');
        }
        if ($status >= 400) {
            $snippet = $this->redactor->redactString($this->snippet((string) $exchange->response->getBody()));
            throw new QueryException(
                sprintf('Space-Track query failed with HTTP %d. %s', $status, $snippet),
                $status,
                $snippet,
            );
        }

        return $exchange;
    }

    private function decode(Query $query, HttpExchange $exchange): QueryResult
    {
        try {
            return $this->decoder->decode(
                $query,
                $exchange->response->getStatusCode(),
                (string) $exchange->response->getBody(),
                $this->headerMap($exchange->response->getHeaders()),
                $exchange->durationSeconds,
            );
        } catch (DecodeException $exception) {
            $this->logger->warning('Space-Track response could not be decoded.', [
                'event' => 'spacetrack.query.decode_failed',
                'class' => $query->className(),
                'format' => $query->outputFormat()->value,
            ]);
            throw $exception;
        }
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function createRequest(string $method, string $path, ?string $body, array $headers, Session $session): RequestInterface
    {
        $uri = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : $this->config->normalizedBaseUrl() . '/' . ltrim($path, '/');

        $request = $this->requests->createRequest($method, $uri)
            ->withHeader('User-Agent', $this->config->userAgent)
            ->withHeader('Accept', '*/*')
            ->withHeader('Cookie', $session->headerValue());

        foreach ($headers as $name => $value) {
            if ($this->isHopByHop($name)) {
                continue;
            }
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streams->createStream($body));
        }

        return $request;
    }

    private function ensureSession(): Session
    {
        $session = $this->sessions->get();
        if (!$session instanceof Session || $session->isExpired($this->clock)) {
            $this->login();
            $session = $this->sessions->get();
        }
        if (!$session instanceof Session) {
            throw new TransportException('Space-Track login did not store a session.');
        }

        return $session;
    }

    private function logRequest(?Query $query, string $method, string $path): void
    {
        $context = [
            'event' => 'spacetrack.http.request',
            'method' => $method,
            'class' => $query?->className(),
            'controller' => $query?->controller(),
            'format' => $query?->outputFormat()->value,
            'limit' => $query?->limitCount(),
            'fields' => $query === null ? [] : array_map(static fn (Criterion $criterion): string => $criterion->field, $query->criteria()),
        ];
        if ($this->config->logPredicateValues) {
            $context['path'] = $this->redactor->redactString($path);
        }

        $this->logger->info('Space-Track request prepared.', $context);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveClass(string $class, ?string $controller): array
    {
        try {
            $resolved = RequestClass::resolve($class, $controller);

            return [$resolved->wireName(), $resolved->controller()->value];
        } catch (\InvalidArgumentException $exception) {
            if ($controller === null) {
                throw $exception;
            }
            Token::assert($class, 'request class');
            Token::assert($controller, 'controller');

            return [strtolower($class), strtolower($controller)];
        }
    }

    private function pathFor(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $raw = (string) $uri;
        if (!str_starts_with($raw, 'http://') && !str_starts_with($raw, 'https://')) {
            return $raw;
        }

        $expected = parse_url($this->config->normalizedBaseUrl());
        $host = strtolower((string) ($expected['host'] ?? ''));
        if (strtolower($uri->getHost()) !== $host) {
            throw new \InvalidArgumentException('Authenticated requests must stay on the configured Space-Track host.');
        }

        $path = $uri->getPath();
        $query = $uri->getQuery();

        return $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * @return array<string, list<string>>
     */
    private function forwardableHeaders(RequestInterface $request): array
    {
        return $this->headerMap($request->getHeaders(), true);
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, list<string>>
     */
    private function headerMap(array $headers, bool $skipHopByHop = false): array
    {
        $mapped = [];
        foreach ($headers as $name => $values) {
            $headerName = (string) $name;
            if ($headerName === '' || ($skipHopByHop && $this->isHopByHop($headerName))) {
                continue;
            }
            if (!is_array($values)) {
                continue;
            }
            $list = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $list[] = $value;
                }
            }
            $mapped[$headerName] = $list;
        }

        return $mapped;
    }

    private function isHopByHop(string $name): bool
    {
        return in_array(strtolower($name), [
            'host',
            'cookie',
            'content-length',
            'connection',
            'transfer-encoding',
            'authorization',
        ], true);
    }

    private function responseFromException(ClientExceptionInterface $exception): ?ResponseInterface
    {
        if (!method_exists($exception, 'getResponse')) {
            return null;
        }
        $response = $exception->getResponse();

        return $response instanceof ResponseInterface ? $response : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function rateHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($this->headerMap($response->getHeaders()) as $name => $values) {
            if (stripos($name, 'rate') !== false) {
                $headers[$name] = $values;
            }
        }

        return $headers;
    }

    private function snippet(string $body): string
    {
        $body = trim($body);
        if (strlen($body) > 180) {
            return substr($body, 0, 180) . '…';
        }

        return $body;
    }

    private function readCachedQuery(Query $query, string $path): ?QueryResult
    {
        if (!$this->cache instanceof CacheInterface) {
            return null;
        }

        $key = $this->queryCacheKey($path);
        $missing = new \stdClass();
        $stored = $this->cache->get($key, $missing);
        if ($stored === $missing) {
            return null;
        }
        $payload = $this->cachedPayload($stored);
        if ($payload === null) {
            $this->cache->delete($key);

            return null;
        }

        try {
            $result = $this->decoder->decode($query, $payload['status'], $payload['body'], $payload['headers'], 0.0);
        } catch (DecodeException) {
            $this->cache->delete($key);

            return null;
        }

        $this->logger->info('Space-Track response reused from cache.', [
            'event' => 'spacetrack.cache.hit',
            'class' => $query->className(),
            'controller' => $query->controller(),
        ]);

        return $result;
    }

    private function storeCachedQuery(string $path, QueryResult $result): QueryResult
    {
        if (!$this->cache instanceof CacheInterface || $result->statusCode < 200 || $result->statusCode >= 400) {
            return $result;
        }

        $this->cache->set($this->queryCacheKey($path), [
            'status' => $result->statusCode,
            'body' => $result->rawBody,
            'headers' => $this->cacheableHeaders($result->headers),
        ], null);

        return $result;
    }

    /**
     * @param array<int|string, Query> $queries
     * @param array<int|string, QueryResult|null> $results
     * @return array<int|string, QueryResult>
     */
    private function cachedBatch(array $queries, array $results): array
    {
        $finished = [];
        foreach (array_keys($queries) as $key) {
            $result = $results[$key] ?? null;
            if (!$result instanceof QueryResult) {
                throw new TransportException('A Space-Track batch result was lost.');
            }
            $finished[$key] = $result;
        }

        return $finished;
    }

    /**
     * @return array{status: int, body: string, headers: array<string, list<string>>}|null
     */
    private function cachedPayload(mixed $stored): ?array
    {
        if (!is_array($stored) || !isset($stored['status'], $stored['body'], $stored['headers'])) {
            return null;
        }
        $status = $stored['status'];
        $body = $stored['body'];
        $headers = $stored['headers'];
        if (!is_int($status) || !is_string($body) || !is_array($headers)) {
            return null;
        }
        if ($status < 200 || $status >= 400) {
            return null;
        }

        $mapped = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                return null;
            }
            $list = [];
            foreach ($values as $value) {
                if (!is_string($value)) {
                    return null;
                }
                $list[] = $value;
            }
            $mapped[$name] = $list;
        }

        return ['status' => $status, 'body' => $body, 'headers' => $mapped];
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private function cacheableHeaders(array $headers): array
    {
        $kept = [];
        foreach ($headers as $name => $values) {
            if ($this->isHopByHop($name) || strcasecmp($name, 'set-cookie') === 0) {
                continue;
            }
            $kept[$name] = $values;
        }

        return $kept;
    }

    private function queryCacheKey(string $path): string
    {
        return 'q.' . hash('sha256', $this->config->normalizedBaseUrl() . "\n" . $path);
    }
}
