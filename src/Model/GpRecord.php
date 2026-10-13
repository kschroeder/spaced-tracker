<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

final readonly class GpRecord
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?int $noradCatId,
        public ?string $objectName,
        public ?string $objectId,
        public ?string $epoch,
        public ?float $meanMotion,
        public ?float $eccentricity,
        public ?float $inclination,
        public ?float $raOfAscNode,
        public ?float $argOfPericenter,
        public ?float $meanAnomaly,
        public ?float $bstar,
        public ?float $periodMinutes,
        public ?float $apoapsisKm,
        public ?float $periapsisKm,
        public ?string $tleLine1,
        public ?string $tleLine2,
        public array $attributes,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Row::int($row, 'NORAD_CAT_ID'),
            Row::string($row, 'OBJECT_NAME'),
            Row::string($row, 'OBJECT_ID'),
            Row::string($row, 'EPOCH'),
            Row::float($row, 'MEAN_MOTION'),
            Row::float($row, 'ECCENTRICITY'),
            Row::float($row, 'INCLINATION'),
            Row::float($row, 'RA_OF_ASC_NODE'),
            Row::float($row, 'ARG_OF_PERICENTER'),
            Row::float($row, 'MEAN_ANOMALY'),
            Row::float($row, 'BSTAR'),
            Row::float($row, 'PERIOD'),
            Row::float($row, 'APOAPSIS'),
            Row::float($row, 'PERIAPSIS'),
            Row::string($row, 'TLE_LINE1'),
            Row::string($row, 'TLE_LINE2'),
            $row,
        );
    }

    public function orbit(): string
    {
        return OrbitRegime::fromElements($this->periodMinutes, $this->eccentricity, $this->apoapsisKm);
    }
}
