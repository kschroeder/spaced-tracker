<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Query;

use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Exception\DecodeException;
use SpacedTracker\Model\RecordMapper;
use SpacedTracker\Query\Query;
use SpacedTracker\Query\ResultDecoder;
use PHPUnit\Framework\TestCase;

final class ResultDecoderTest extends TestCase
{
    private ResultDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new ResultDecoder();
    }

    public function testDecodesAJsonListAndHydratesSatcat(): void
    {
        $body = (string) json_encode([
            [
                'NORAD_CAT_ID' => '25544',
                'SATNAME' => 'ISS (ZARYA)',
                'OBJECT_TYPE' => 'PAY',
                'COUNTRY' => 'ISS',
                'INTLDES' => '1998-067A',
                'PERIOD' => '92.9',
                'APOGEE' => '421',
                'PERIGEE' => '418',
                'CURRENT' => 'Y',
            ],
        ]);
        $result = $this->decoder->decode($this->query(RequestClass::Satcat), 200, $body, [], 0.01);
        $records = (new RecordMapper())->satcat($result);

        self::assertCount(1, $records);
        self::assertSame(25544, $records[0]->noradCatId);
        self::assertSame('ISS (ZARYA)', $records[0]->name);
        self::assertSame('LEO', $records[0]->orbit());
        self::assertSame('ON ORBIT', $records[0]->status());
    }

    public function testUnwrapsMetadata(): void
    {
        $body = (string) json_encode([
            'request_metadata' => ['Total' => 1],
            'data' => [['NORAD_CAT_ID' => 25544, 'OBJECT_NAME' => 'ISS (ZARYA)', 'EPOCH' => '2026-01-01 00:00:00']],
        ]);
        $result = $this->decoder->decode(
            $this->query(RequestClass::Gp)->withMetadata(),
            200,
            $body,
            [],
            0.2,
        );

        self::assertSame(1, $result->metadata['Total'] ?? null);
        self::assertSame('ISS (ZARYA)', (new RecordMapper())->gp($result)[0]->objectName);
    }

    public function testDecodesCsvAndTle(): void
    {
        $csv = "NORAD_CAT_ID,OBJECT_NAME\n25544,ISS (ZARYA)\n";
        $csvResult = $this->decoder->decode($this->query(RequestClass::Satcat)->format(OutputFormat::Csv), 200, $csv, [], 0.0);
        self::assertSame('ISS (ZARYA)', $csvResult->records[0]['OBJECT_NAME'] ?? null);

        $tle = "ISS (ZARYA)\n1 25544U 98067A   26001.00000000  .00000000  00000-0  00000-0 0  9992\n2 25544  51.6400  12.0000 0003000  12.0000  12.0000 15.50000000000012\n";
        $tleResult = $this->decoder->decode($this->query(RequestClass::Gp)->format(OutputFormat::Tle), 200, $tle, [], 0.0);
        self::assertSame('ISS (ZARYA)', $tleResult->records[0]['TLE_LINE0'] ?? null);
        self::assertIsString($tleResult->records[0]['TLE_LINE2'] ?? null);
    }

    public function testEmptyBodyIsAnEmptyResult(): void
    {
        $result = $this->decoder->decode($this->query(RequestClass::Gp), 204, '', [], 0.0);
        self::assertSame([], $result->records);
    }

    public function testInvalidJsonThrows(): void
    {
        try {
            $this->decoder->decode($this->query(RequestClass::Gp), 200, '{', [], 0.0);
            self::fail('Invalid JSON must fail.');
        } catch (DecodeException $exception) {
            self::assertStringContainsString('basicspacedata/gp', $exception->getMessage());
            self::assertStringContainsString('json', $exception->getMessage());
        }
    }

    public function testJsonNullScalarAndObjectShapes(): void
    {
        $null = $this->decoder->decode($this->query(RequestClass::Gp), 200, 'null', [], 0.0);
        self::assertSame([], $null->records);
        self::assertSame(0, $null->count());

        $object = $this->decoder->decode($this->query(RequestClass::Gp), 200, '{"NORAD_CAT_ID":25544}', [], 0.0);
        self::assertSame(25544, $object->records[0]['NORAD_CAT_ID'] ?? null);

        $dataObject = $this->decoder->decode(
            $this->query(RequestClass::Gp),
            200,
            '{"request_metadata":{"Total":1},"data":{"NORAD_CAT_ID":25544}}',
            [],
            0.0,
        );
        self::assertSame(25544, $dataObject->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertSame(1, $dataObject->metadata['Total'] ?? null);

        foreach (['"text"', '[1]', '{"data":[1]}'] as $body) {
            try {
                $this->decoder->decode($this->query(RequestClass::Gp), 200, $body, [], 0.0);
                self::fail('Malformed JSON records must fail.');
            } catch (DecodeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testUnstructuredFormatsAndMessyCsvOrTleStillDecode(): void
    {
        foreach ([OutputFormat::Xml, OutputFormat::Html, OutputFormat::Kvn, OutputFormat::Stream] as $format) {
            $result = $this->decoder->decode($this->query(RequestClass::Gp)->format($format), 200, '<unused>', [], 0.0);
            self::assertSame([], $result->records);
        }

        $csv = "NORAD_CAT_ID,,OBJECT_NAME\n\n25544,skip,ISS (ZARYA)\n";
        $csvResult = $this->decoder->decode($this->query(RequestClass::Satcat)->format(OutputFormat::Csv), 200, $csv, [], 0.0);
        self::assertSame(['NORAD_CAT_ID' => '25544', 'OBJECT_NAME' => 'ISS (ZARYA)'], $csvResult->records[0]);
        self::assertSame(
            [],
            $this->decoder->decode($this->query(RequestClass::Satcat)->format(OutputFormat::Csv), 200, "NORAD_CAT_ID\n", [], 0.0)->records,
        );

        $tle = "\n2 25544  51.6400  12.0000 0003000  12.0000  12.0000 15.50000000000012\n1 25544U 98067A   26001.00000000  .00000000  00000-0  00000-0 0  9992\n";
        $tleResult = $this->decoder->decode($this->query(RequestClass::Gp)->format(OutputFormat::ThreeLine), 200, $tle, [], 0.0);
        self::assertStringStartsWith('2 25544', (string) ($tleResult->records[0]['TLE_LINE0'] ?? ''));
        self::assertStringStartsWith('1 25544', (string) ($tleResult->records[0]['TLE_LINE1'] ?? ''));

        $spaced = "ISS (ZARYA)\n\n1 25544U 98067A   26001.00000000  .00000000  00000-0  00000-0 0  9992\n2 25544  51.6400  12.0000 0003000  12.0000  12.0000 15.50000000000012\n";
        $blank = $this->decoder->decode($this->query(RequestClass::Gp)->format(OutputFormat::Tle), 200, $spaced, [], 0.0);
        self::assertSame('ISS (ZARYA)', $blank->records[0]['TLE_LINE0'] ?? null);
        self::assertStringStartsWith('1 25544', (string) ($blank->records[0]['TLE_LINE1'] ?? ''));
        self::assertStringStartsWith('2 25544', (string) ($blank->records[0]['TLE_LINE2'] ?? ''));
    }

    private function query(RequestClass $class): Query
    {
        return Query::create($class);
    }
}
