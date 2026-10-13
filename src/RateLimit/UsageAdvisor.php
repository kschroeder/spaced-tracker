<?php

declare(strict_types=1);

namespace SpacedTracker\RateLimit;

use SpacedTracker\Exception\AdvisoryLimitException;
use SpacedTracker\Query\Query;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Warns on bulk downloads that Space-Track asks users to cache.
 *
 * Selective queries (a catalog id, a file id, or a small limit) stay quiet so
 * an interactive catalog can page through objects. Enforcement is off unless
 * the client is configured to throw.
 */
final class UsageAdvisor
{
    /** @var array<string, float> */
    private array $lastBulkAt = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly bool $enforce = false,
    ) {
    }

    public function inspect(Query $query): void
    {
        $warning = $this->warning($query);
        if ($warning === null || !$this->isBulk($query)) {
            return;
        }

        $this->logger->warning($warning['message'], [
            'event' => 'spacetrack.ratelimit.advisory',
            'class' => $query->className(),
            'controller' => $query->controller(),
            'window_seconds' => $warning['window'],
        ]);

        if (!$this->enforce) {
            return;
        }

        $key = $query->controller() . '.' . $query->className();
        $now = (float) $this->clock->now()->format('U.u');
        $previous = $this->lastBulkAt[$key] ?? null;
        if ($previous !== null && ($now - $previous) < $warning['window']) {
            throw new AdvisoryLimitException($warning['message']);
        }

        $this->lastBulkAt[$key] = $now;
    }

    public function isBulk(Query $query): bool
    {
        if ($query->className() === 'gp_history') {
            return !$this->hasSelectivePredicate($query) || $query->limitCount() === null || $query->limitCount() > 30;
        }

        if ($this->hasSelectivePredicate($query) && $query->limitCount() !== null && $query->limitCount() <= 100) {
            return false;
        }

        return $query->criteria() === [] || $query->limitCount() === null || $query->limitCount() > 100;
    }

    /**
     * @return array{message: string, window: int}|null
     */
    private function warning(Query $query): ?array
    {
        return match ($query->className()) {
            'gp' => [
                'message' => 'Bulk GP downloads should run at most once an hour, off the top and bottom of the hour. Store the result locally.',
                'window' => 3600,
            ],
            'gp_history' => [
                'message' => 'Do not use GP_HISTORY for current orbits. Download an object history once and store it locally.',
                'window' => 86400,
            ],
            'satcat' => [
                'message' => 'A full SATCAT pull should happen at most once per day, after 17:00 UTC.',
                'window' => 86400,
            ],
            'boxscore' => [
                'message' => 'Boxscore should be downloaded at most once per day, after 17:00 UTC.',
                'window' => 86400,
            ],
            'decay' => [
                'message' => 'Store decay history locally. Repeat full decay downloads at most once per day.',
                'window' => 86400,
            ],
            'tip' => [
                'message' => 'TIP messages should be polled at most once per day unless the query is limited to a current message window.',
                'window' => 86400,
            ],
            'cdm', 'cdm_public' => [
                'message' => 'Full conjunction downloads should run at most every eight hours. Poll a single event at most hourly.',
                'window' => 28800,
            ],
            'download' => [
                'message' => 'Cache downloaded files. Space-Track asks that public and shared files not be fetched again.',
                'window' => 28800,
            ],
            default => null,
        };
    }

    private function hasSelectivePredicate(Query $query): bool
    {
        foreach ($query->criteria() as $criterion) {
            $field = strtoupper($criterion->field);
            if (in_array($field, ['NORAD_CAT_ID', 'OBJECT_NUMBER', 'OBJECT_ID', 'INTLDES', 'GP_ID', 'FILE_ID', 'FOLDER_ID'], true)) {
                return true;
            }
        }

        return false;
    }
}
