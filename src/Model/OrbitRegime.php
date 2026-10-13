<?php

declare(strict_types=1);

namespace SpacedTracker\Model;

/**
 * Rough Earth-orbit regime from a SATCAT period, eccentricity, and apogee.
 *
 * Period is in minutes. The boundaries are operational labels for a catalog
 * UI, not a precise orbit determination.
 */
final class OrbitRegime
{
    public const LEO = 'LEO';
    public const MEO = 'MEO';
    public const GEO = 'GEO';
    public const HEO = 'HEO';
    public const UNKNOWN = 'UNKNOWN';

    public static function fromElements(?float $periodMinutes, ?float $eccentricity = null, ?float $apogeeKm = null): string
    {
        if ($eccentricity !== null && $eccentricity >= 0.25 && ($apogeeKm === null || $apogeeKm > 2000)) {
            return self::HEO;
        }

        if ($periodMinutes === null || $periodMinutes <= 0) {
            return self::UNKNOWN;
        }

        if ($periodMinutes < 225) {
            return self::LEO;
        }

        if ($periodMinutes >= 1200 && $periodMinutes <= 1800) {
            return self::GEO;
        }

        if ($periodMinutes >= 225 && $periodMinutes < 1200) {
            return self::MEO;
        }

        return self::UNKNOWN;
    }
}
