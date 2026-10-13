<?php

declare(strict_types=1);

namespace SpacedTracker\Catalog;

enum Controller: string
{
    case BasicSpaceData = 'basicspacedata';
    case ExpandedSpaceData = 'expandedspacedata';
    case FileShare = 'fileshare';
    case Spephemeris = 'spephemeris';
    case PublicFiles = 'publicfiles';

    public function label(): string
    {
        return match ($this) {
            self::BasicSpaceData => 'Basic space data',
            self::ExpandedSpaceData => 'Expanded space data',
            self::FileShare => 'File share',
            self::Spephemeris => 'Special perturbations ephemeris',
            self::PublicFiles => 'Public files',
        };
    }
}
