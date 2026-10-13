<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

final readonly class BoxscoreRecord
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?string $country,
        public ?int $orbitalPayloadCount,
        public ?int $orbitalRocketBodyCount,
        public ?int $orbitalDebrisCount,
        public ?int $orbitalTotalCount,
        public ?int $decayedTotalCount,
        public array $attributes,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Row::string($row, 'COUNTRY'),
            Row::int($row, 'ORBITAL_PAYLOAD_COUNT'),
            Row::int($row, 'ORBITAL_ROCKET_BODY_COUNT'),
            Row::int($row, 'ORBITAL_DEBRIS_COUNT'),
            Row::int($row, 'ORBITAL_TOTAL_COUNT'),
            Row::int($row, 'DECAYED_TOTAL_COUNT'),
            $row,
        );
    }
}
