<?php

declare(strict_types=1);

namespace SpacedTracker\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface ConcurrentClientInterface
{
    /**
     * Send every request and return responses under the same keys.
     *
     * Implementations may issue the calls concurrently. A failure of any
     * call fails the batch. Keys are preserved.
     *
     * @param array<int|string, RequestInterface> $requests
     * @return array<int|string, ResponseInterface>
     */
    public function send(array $requests): array;
}
