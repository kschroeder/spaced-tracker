<?php

declare(strict_types=1);

namespace SpacedTracker\Http;

use Psr\Http\Message\ResponseInterface;

final readonly class HttpExchange
{
    public function __construct(
        public ResponseInterface $response,
        public float $durationSeconds,
    ) {
    }
}
