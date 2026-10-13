<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

final readonly class SatcatRecord
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?int $noradCatId,
        public ?string $name,
        public ?string $objectType,
        public ?string $country,
        public ?string $internationalDesignator,
        public ?string $launchDate,
        public ?string $site,
        public ?string $decayDate,
        public ?float $periodMinutes,
        public ?float $inclination,
        public ?float $apogeeKm,
        public ?float $perigeeKm,
        public ?string $rcsSize,
        public ?string $current,
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
            Row::string($row, 'SATNAME') ?? Row::string($row, 'OBJECT_NAME'),
            Row::string($row, 'OBJECT_TYPE'),
            Row::string($row, 'COUNTRY') ?? Row::string($row, 'COUNTRY_CODE'),
            Row::string($row, 'INTLDES') ?? Row::string($row, 'OBJECT_ID'),
            Row::string($row, 'LAUNCH') ?? Row::string($row, 'LAUNCH_DATE'),
            Row::string($row, 'SITE'),
            Row::string($row, 'DECAY') ?? Row::string($row, 'DECAY_DATE'),
            Row::float($row, 'PERIOD'),
            Row::float($row, 'INCLINATION'),
            Row::float($row, 'APOGEE') ?? Row::float($row, 'APOAPSIS'),
            Row::float($row, 'PERIGEE') ?? Row::float($row, 'PERIAPSIS'),
            Row::string($row, 'RCS_SIZE'),
            Row::string($row, 'CURRENT'),
            $row,
        );
    }

    public function orbit(): string
    {
        return OrbitRegime::fromElements($this->periodMinutes, null, $this->apogeeKm);
    }

    public function status(): string
    {
        if ($this->decayDate !== null) {
            return 'DECAYED';
        }

        return $this->current === 'N' ? 'HISTORICAL' : 'ON ORBIT';
    }
}
