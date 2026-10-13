<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

final readonly class QueryResult
{
    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed>|null $metadata
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public Query $query,
        public int $statusCode,
        public string $rawBody,
        public array $records,
        public ?array $metadata,
        public float $durationSeconds,
        public array $headers,
    ) {
    }

    public function count(): int
    {
        return count($this->records);
    }
}
