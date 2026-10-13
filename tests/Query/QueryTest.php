<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Query;

use SpacedTracker\Catalog\Controller;
use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Query\Direction;
use SpacedTracker\Query\Query;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    public function testExposesTheSelectionsItWillCompile(): void
    {
        $query = Query::create(RequestClass::Gp)
            ->where('NORAD_CAT_ID', 25544)
            ->orderBy('EPOCH', Direction::Desc)
            ->limit(2, 3)
            ->columns(['NORAD_CAT_ID', 'EPOCH'])
            ->distinct()
            ->withMetadata()
            ->favorites('Human Spaceflight')
            ->showEmpty(false)
            ->format(OutputFormat::Tle)
            ->withQueryParameters(['name']);

        self::assertSame('gp', $query->className());
        self::assertSame('basicspacedata', $query->controller());
        self::assertSame('NORAD_CAT_ID', $query->criteria()[0]->field);
        self::assertSame('25544', $query->criteria()[0]->value);
        self::assertSame('EPOCH', $query->orders()[0]->field);
        self::assertSame(Direction::Desc, $query->orders()[0]->direction);
        self::assertSame(2, $query->limitCount());
        self::assertSame(3, $query->offset());
        self::assertSame(['NORAD_CAT_ID', 'EPOCH'], $query->selectedColumns());
        self::assertTrue($query->isDistinct());
        self::assertTrue($query->includesMetadata());
        self::assertSame(['Human Spaceflight'], $query->favoriteNames());
        self::assertFalse($query->showsEmpty());
        self::assertSame(OutputFormat::Tle, $query->outputFormat());
        self::assertSame(['name'], $query->queryParameterFields());
    }

    public function testRejectsWindowsFavoritesAndTokensThatWouldChangeThePath(): void
    {
        $query = Query::create(RequestClass::Gp);
        $cases = [
            static fn () => $query->limit(0),
            static fn () => $query->limit(1, -1),
            static fn () => $query->favorites(''),
            static fn () => $query->favorites('a/b'),
            static fn () => $query->favorites('a?b'),
            static fn () => $query->columns(['bad-name']),
            static fn () => $query->orderBy('1bad'),
            static fn () => $query->withQueryParameters(['bad-name']),
            static fn () => Query::custom('future-class', Controller::BasicSpaceData),
            static fn () => Query::custom('future_class', 'not a controller'),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('The query must reject this value.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
