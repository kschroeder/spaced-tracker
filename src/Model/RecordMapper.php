<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

use SpacedTracker\Query\QueryResult;

final class RecordMapper
{
    /**
     * @return list<SatcatRecord>
     */
    public function satcat(QueryResult $result): array
    {
        return array_map(static fn (array $row): SatcatRecord => SatcatRecord::fromArray($row), $result->records);
    }

    /**
     * @return list<GpRecord>
     */
    public function gp(QueryResult $result): array
    {
        return array_map(static fn (array $row): GpRecord => GpRecord::fromArray($row), $result->records);
    }

    /**
     * @return list<DecayRecord>
     */
    public function decay(QueryResult $result): array
    {
        return array_map(static fn (array $row): DecayRecord => DecayRecord::fromArray($row), $result->records);
    }

    /**
     * @return list<TipRecord>
     */
    public function tip(QueryResult $result): array
    {
        return array_map(static fn (array $row): TipRecord => TipRecord::fromArray($row), $result->records);
    }

    /**
     * @return list<BoxscoreRecord>
     */
    public function boxscore(QueryResult $result): array
    {
        return array_map(static fn (array $row): BoxscoreRecord => BoxscoreRecord::fromArray($row), $result->records);
    }

    /**
     * @return list<LaunchSiteRecord>
     */
    public function launchSites(QueryResult $result): array
    {
        return array_map(static fn (array $row): LaunchSiteRecord => LaunchSiteRecord::fromArray($row), $result->records);
    }
}
