<?php

declare(strict_types=1);

namespace SpacedTracker\Testing;

use Psr\Log\AbstractLogger;
use Stringable;

final class ArrayLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function contains(string $needle): bool
    {
        foreach ($this->records as $record) {
            $encoded = json_encode($record);
            if (is_string($encoded) && str_contains($encoded, $needle)) {
                return true;
            }
            if (str_contains($record['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
