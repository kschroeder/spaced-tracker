<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

final readonly class TipRecord
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?int $noradCatId,
        public ?string $messageEpoch,
        public ?string $decayEpoch,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $direction,
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
            Row::string($row, 'MSG_EPOCH'),
            Row::string($row, 'DECAY_EPOCH'),
            Row::float($row, 'LAT'),
            Row::float($row, 'LON'),
            Row::string($row, 'DIRECTION'),
            $row,
        );
    }
}
