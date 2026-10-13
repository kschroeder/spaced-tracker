<?php

declare(strict_types=1);

namespace SpacedTracker\Exception;

final class QueryException extends SpaceTrackException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly string $responseBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
