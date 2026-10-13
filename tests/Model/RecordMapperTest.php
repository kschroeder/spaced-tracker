<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Model;

use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Model\RecordMapper;
use SpacedTracker\Query\Query;
use SpacedTracker\Query\QueryResult;
use PHPUnit\Framework\TestCase;

final class RecordMapperTest extends TestCase
{
    private RecordMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new RecordMapper();
    }

    public function testHydratesDecayTipBoxscoreAndLaunchSites(): void
    {
        $decay = $this->mapper->decay($this->rows(RequestClass::Decay, [[
            'norad_cat_id' => '7530',
            'OBJECT_NAME' => 'SKYLAB',
            'OBJECT_ID' => '1973-027A',
            'MSG_EPOCH' => '1979-07-11',
            'DECAY_EPOCH' => '1979-07-11',
            'SOURCE' => 'DEMO',
            'MSG_TYPE' => 'HISTORICAL',
            'PRECEDENCE' => '0',
            'notes' => ['ignored'],
        ]]))[0];
        self::assertSame(7530, $decay->noradCatId);
        self::assertSame('1973-027A', $decay->internationalDesignator);
        self::assertSame(0, $decay->precedence);
        self::assertSame('SKYLAB', $decay->objectName);

        $withDesignator = $this->mapper->decay($this->rows(RequestClass::Decay, [[
            'INTLDES' => '1998-067A',
            'OBJECT_ID' => 'fallback',
        ]]))[0];
        self::assertSame('1998-067A', $withDesignator->internationalDesignator);

        $tip = $this->mapper->tip($this->rows(RequestClass::Tip, [[
            'NORAD_CAT_ID' => 7530,
            'MSG_EPOCH' => '1979-07-11T00:00:00',
            'DECAY_EPOCH' => '1979-07-11T16:37:00',
            'LAT' => '-43.5',
            'LON' => 95,
            'DIRECTION' => 'DEMO',
        ]]))[0];
        self::assertSame(-43.5, $tip->latitude);
        self::assertSame(95.0, $tip->longitude);
        self::assertSame('DEMO', $tip->direction);

        $box = $this->mapper->boxscore($this->rows(RequestClass::Boxscore, [[
            'COUNTRY' => 'US',
            'ORBITAL_PAYLOAD_COUNT' => '5',
            'ORBITAL_ROCKET_BODY_COUNT' => 1,
            'ORBITAL_DEBRIS_COUNT' => '',
            'ORBITAL_TOTAL_COUNT' => 6,
            'DECAYED_TOTAL_COUNT' => 'nope',
        ]]))[0];
        self::assertSame('US', $box->country);
        self::assertSame(5, $box->orbitalPayloadCount);
        self::assertNull($box->orbitalDebrisCount);
        self::assertNull($box->decayedTotalCount);

        $site = $this->mapper->launchSites($this->rows(RequestClass::LaunchSite, [[
            'SITE_CODE' => 'AFETR',
            'LAUNCH_SITE' => 'CAPE CANAVERAL',
        ]]))[0];
        self::assertSame('AFETR', $site->siteCode);
        self::assertSame('CAPE CANAVERAL', $site->launchSite);
    }

    public function testSatcatFallsBackAcrossFieldNamesAndStatuses(): void
    {
        $historical = $this->mapper->satcat($this->rows(RequestClass::Satcat, [[
            'OBJECT_NAME' => 'OLD NAME',
            'COUNTRY_CODE' => 'US',
            'OBJECT_ID' => '1990-037B',
            'LAUNCH_DATE' => '1990-04-24',
            'DECAY_DATE' => '2020-01-01',
            'APOAPSIS' => '540',
            'PERIAPSIS' => '535',
            'CURRENT' => 'N',
        ]]))[0];
        self::assertSame('OLD NAME', $historical->name);
        self::assertSame('US', $historical->country);
        self::assertSame('1990-037B', $historical->internationalDesignator);
        self::assertSame('1990-04-24', $historical->launchDate);
        self::assertSame(540.0, $historical->apogeeKm);
        self::assertSame(535.0, $historical->perigeeKm);
        self::assertSame('DECAYED', $historical->status());

        $onOrbit = $this->mapper->satcat($this->rows(RequestClass::Satcat, [[
            'SATNAME' => 'ISS (ZARYA)',
            'CURRENT' => 'Y',
            'PERIOD' => '700',
            'APOGEE' => '40000',
        ]]))[0];
        self::assertSame('ON ORBIT', $onOrbit->status());

        $retired = $this->mapper->satcat($this->rows(RequestClass::Satcat, [
            ['CURRENT' => 'N'],
        ]))[0];
        self::assertSame('HISTORICAL', $retired->status());
    }

    public function testGpOrbitUsesEccentricityWhenThePeriodWouldOtherwiseBeMeo(): void
    {
        $record = $this->mapper->gp($this->rows(RequestClass::Gp, [[
            'NORAD_CAT_ID' => 25544,
            'OBJECT_NAME' => 'ISS (ZARYA)',
            'PERIOD' => '700',
            'ECCENTRICITY' => '0.7',
            'APOAPSIS' => '40000',
        ]]))[0];

        self::assertSame('HEO', $record->orbit());
        self::assertSame(25544, $record->noradCatId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rows(RequestClass $class, array $rows): QueryResult
    {
        return new QueryResult(Query::create($class), 200, '', $rows, null, 0.0, []);
    }
}
