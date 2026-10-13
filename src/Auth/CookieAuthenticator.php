<?php

declare(strict_types=1);

namespace SpacedTracker\Auth;

use SpacedTracker\Config\ClientConfig;
use SpacedTracker\Exception\AuthenticationException;
use SpacedTracker\Exception\TransportException;
use SpacedTracker\Log\Redactor;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Posts the Space-Track login form and keeps the session cookie locally.
 *
 * The password is placed only in the request body. Callers must not log that
 * request. Failures report status codes, never the submitted secret.
 */
final class CookieAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly ClockInterface $clock,
        private readonly ClientConfig $config,
        private readonly CookieParser $cookies = new CookieParser(),
        private readonly Redactor $redactor = new Redactor(),
    ) {
    }

    public function login(Credentials $credentials): Session
    {
        $body = http_build_query([
            'identity' => $credentials->identity(),
            'password' => $credentials->password(),
        ], '', '&', PHP_QUERY_RFC3986);

        $request = $this->requests->createRequest('POST', $this->config->normalizedBaseUrl() . '/ajaxauth/login')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json, text/plain, */*')
            ->withHeader('User-Agent', $this->config->userAgent)
            ->withBody($this->streams->createStream($body));

        $response = $this->send($request);
        $status = $response->getStatusCode();
        $now = $this->clock->now();
        $parsed = $this->cookies->parse($response, $now, $this->config->sessionTtlSeconds);

        if ($status >= 400 || $parsed['cookies'] === []) {
            $snippet = $this->redactor->redactString($this->snippet((string) $response->getBody()));
            throw new AuthenticationException(sprintf(
                'Space-Track rejected the login (HTTP %d). %s',
                $status,
                $snippet === '' ? 'No session cookie was returned.' : $snippet,
            ));
        }

        return new Session($parsed['cookies'], $now, $parsed['expiresAt']);
    }

    public function logout(Session $session): void
    {
        $request = $this->requests->createRequest('GET', $this->config->normalizedBaseUrl() . '/ajaxauth/logout')
            ->withHeader('Cookie', $session->headerValue())
            ->withHeader('User-Agent', $this->config->userAgent);

        try {
            $this->send($request);
        } catch (TransportException) {
            // Logout is best-effort. The local session is cleared by the caller.
        }
    }

    private function send(RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException('Space-Track authentication request failed.', 0, $exception);
        }
    }

    private function snippet(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if (strlen($body) > 180) {
            return substr($body, 0, 180) . '…';
        }

        return $body;
    }
}
