<?php

declare(strict_types=1);

namespace SpacedTracker\Testing;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param list<ResponseInterface|callable(RequestInterface): ResponseInterface> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->responses === []) {
            throw new RuntimeException('Mock HTTP client has no queued responses.');
        }

        $next = array_shift($this->responses);
        if ($next instanceof ResponseInterface) {
            return $next;
        }

        return $next($request);
    }
}
