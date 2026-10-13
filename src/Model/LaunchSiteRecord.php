<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

final readonly class LaunchSiteRecord
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public ?string $siteCode,
        public ?string $launchSite,
        public array $attributes,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Row::string($row, 'SITE_CODE'),
            Row::string($row, 'LAUNCH_SITE'),
            $row,
        );
    }
}
