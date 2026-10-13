<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Model;

use SpacedTracker\Model\OrbitRegime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrbitRegimeTest extends TestCase
{
    #[DataProvider('elements')]
    public function testLabelsRegimesFromPeriodEccentricityAndApogee(
        ?float $period,
        ?float $eccentricity,
        ?float $apogee,
        string $expected,
    ): void {
        self::assertSame($expected, OrbitRegime::fromElements($period, $eccentricity, $apogee));
    }

    /**
     * @return list<array{0: ?float, 1: ?float, 2: ?float, 3: string}>
     */
    public static function elements(): array
    {
        return [
            [null, null, null, OrbitRegime::UNKNOWN],
            [0.0, null, null, OrbitRegime::UNKNOWN],
            [-1.0, null, null, OrbitRegime::UNKNOWN],
            [92.9, null, 421.0, OrbitRegime::LEO],
            [224.9, 0.01, 2000.0, OrbitRegime::LEO],
            [225.0, 0.01, 2000.0, OrbitRegime::MEO],
            [717.0, null, 20200.0, OrbitRegime::MEO],
            [1199.0, 0.01, 2000.0, OrbitRegime::MEO],
            [1200.0, 0.01, 35786.0, OrbitRegime::GEO],
            [1436.0, 0.001, 35790.0, OrbitRegime::GEO],
            [1800.0, 0.01, 35786.0, OrbitRegime::GEO],
            [1801.0, 0.01, 40000.0, OrbitRegime::UNKNOWN],
            [700.0, 0.25, null, OrbitRegime::HEO],
            [700.0, 0.25, 2001.0, OrbitRegime::HEO],
            [92.0, 0.25, 2000.0, OrbitRegime::LEO],
            [700.0, 0.24, 40000.0, OrbitRegime::MEO],
        ];
    }
}
