<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

final readonly class DecayRecord
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?int $noradCatId,
        public ?string $objectName,
        public ?string $internationalDesignator,
        public ?string $messageEpoch,
        public ?string $decayEpoch,
        public ?string $source,
        public ?string $messageType,
        public ?int $precedence,
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
            Row::string($row, 'INTLDES') ?? Row::string($row, 'OBJECT_ID'),
            Row::string($row, 'MSG_EPOCH'),
            Row::string($row, 'DECAY_EPOCH'),
            Row::string($row, 'SOURCE'),
            Row::string($row, 'MSG_TYPE'),
            Row::int($row, 'PRECEDENCE'),
            $row,
        );
    }
}
