<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Query;

use SpacedTracker\Query\ValueEncoder;
use PHPUnit\Framework\TestCase;

final class ValueEncoderTest extends TestCase
{
    public function testEncodesScalarsDatesAndLists(): void
    {
        self::assertSame('null-val', ValueEncoder::encode(null));
        self::assertSame('true', ValueEncoder::encode(true));
        self::assertSame('false', ValueEncoder::encode(false));
        self::assertSame('25544', ValueEncoder::encode(25544));
        self::assertSame('1.5', ValueEncoder::encode(1.5));
        self::assertSame('-1.5', ValueEncoder::encode(-1.5));
        self::assertSame('10', ValueEncoder::encode(10.0));
        self::assertSame('0', ValueEncoder::encode(0.0));
        self::assertSame(
            '2026-03-01 17:00:00',
            ValueEncoder::encode(new \DateTimeImmutable('2026-03-01 17:00:00 UTC')),
        );
        self::assertSame('1,null-val,true,ISS', ValueEncoder::encode([1, null, true, 'ISS']));
        self::assertSame('ISS', ValueEncoder::encode('ISS'));
        self::assertSame("line\nbreak", ValueEncoder::encode("line\nbreak"));
        self::assertSame("tab\t", ValueEncoder::encode("tab\t"));
    }

    public function testRejectsValuesThatCannotBeAPredicate(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);
        $cases = [
            [INF, 'finite'],
            [NAN, 'finite'],
            [[], 'empty'],
            [[1, ['nested']], 'scalars'],
            [new \stdClass(), 'Unsupported'],
            ['', 'Operator::isNull()'],
            ["\x01", 'control'],
            ["\x7F", 'control'],
            [$resource, 'Unsupported'],
        ];

        try {
            foreach ($cases as [$value, $needle]) {
                try {
                    ValueEncoder::encode($value);
                    self::fail('The predicate value must be rejected.');
                } catch (\InvalidArgumentException $exception) {
                    self::assertStringContainsString($needle, $exception->getMessage());
                }
            }
        } finally {
            fclose($resource);
        }
    }
}
