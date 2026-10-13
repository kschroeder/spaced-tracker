<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Query;

use DateTimeImmutable;
use SpacedTracker\Catalog\Controller;
use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Query\Direction;
use SpacedTracker\Query\Operator;
use SpacedTracker\Query\PathCompiler;
use SpacedTracker\Query\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathCompilerTest extends TestCase
{
    private PathCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new PathCompiler();
    }

    public function testCompilesASelectiveGpQuery(): void
    {
        $path = $this->compiler->compile(
            Query::create(RequestClass::Gp)
                ->where('NORAD_CAT_ID', 25544)
                ->where('EPOCH', Operator::greaterThan('now-10'))
                ->where('DECAY_DATE', Operator::isNull())
                ->orderBy('EPOCH', Direction::Desc)
                ->limit(1)
                ->columns(['NORAD_CAT_ID', 'EPOCH'])
                ->format(OutputFormat::Tle),
        );

        self::assertSame(
            '/basicspacedata/query/class/gp/NORAD_CAT_ID/25544/EPOCH/%3Enow-10/DECAY_DATE/null-val/orderby/EPOCH%20desc/limit/1/predicates/NORAD_CAT_ID,EPOCH/emptyresult/show/format/tle',
            $path,
        );
    }

    public function testEncodesOperatorsAndPreservesCommas(): void
    {
        $path = $this->compiler->compile(
            Query::create(RequestClass::Satcat)
                ->where('NORAD_CAT_ID', Operator::anyOf([25544, 20580]))
                ->where('SATNAME', Operator::like('ISS'))
                ->where('OBJECT_NAME', Operator::startsWith('NAV'))
                ->where('COUNTRY', Operator::notEqual('PRC'))
                ->where('APOGEE', Operator::lessThan(2000))
                ->where('LAUNCH', Operator::between('2020-01-01', '2020-12-31'))
                ->limit(10, 20)
                ->distinct()
                ->withMetadata()
                ->favorites('Human Spaceflight')
                ->showEmpty(false)
                ->format(OutputFormat::Json),
        );

        self::assertStringContainsString('/NORAD_CAT_ID/25544,20580/', $path);
        self::assertStringContainsString('/SATNAME/~~ISS/', $path);
        self::assertStringContainsString('/OBJECT_NAME/%5ENAV/', $path);
        self::assertStringContainsString('/COUNTRY/%3C%3EPRC/', $path);
        self::assertStringContainsString('/APOGEE/%3C2000/', $path);
        self::assertStringContainsString('/LAUNCH/2020-01-01--2020-12-31/', $path);
        self::assertStringContainsString('/limit/10,20/', $path);
        self::assertStringContainsString('/distinct/true/', $path);
        self::assertStringContainsString('/metadata/true/', $path);
        self::assertStringContainsString('/favorites/Human%20Spaceflight/', $path);
        self::assertStringNotContainsString('/emptyresult/', $path);
    }

    public function testPublicDownloadUsesTheQueryString(): void
    {
        $path = $this->compiler->compile(
            Query::create(RequestClass::PublicDownload)
                ->where('name', 'ephemeris/public.zip')
                ->showEmpty(false),
        );

        self::assertSame(
            '/publicfiles/query/class/download/format/json?name=ephemeris%2Fpublic.zip',
            $path,
        );
    }

    public function testEveryRequestClassHasAStablePrefix(): void
    {
        foreach (RequestClass::cases() as $class) {
            $path = $this->compiler->compile(Query::create($class)->showEmpty(false)->format(OutputFormat::Json));
            self::assertStringStartsWith(
                '/' . $class->controller()->value . '/query/class/' . $class->wireName() . '/',
                $path,
            );
        }
    }

    public function testCustomClassIsAcceptedWhenTheTokenIsSafe(): void
    {
        $path = $this->compiler->compile(
            Query::custom('future_class', Controller::BasicSpaceData)->showEmpty(false),
        );

        self::assertStringStartsWith('/basicspacedata/query/class/future_class/', $path);
    }

    public function testRejectsPathInjectionInFieldNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Query::create(RequestClass::Gp)->where('../admin', '1');
    }

    public function testEncodesDatesAndRelativeDays(): void
    {
        $path = $this->compiler->compile(
            Query::create(RequestClass::Decay)->where('MSG_EPOCH', new DateTimeImmutable('2026-03-01 17:00:00 UTC')),
        );

        self::assertStringContainsString('/MSG_EPOCH/2026-03-01%2017%3A00%3A00/', $path);
        self::assertSame('now-10', Operator::relativeToNow(-10));
        self::assertSame('now', Operator::relativeToNow(0));
    }

    public function testDuplicateWireNamesRequireAController(): void
    {
        $download = RequestClass::resolve('download', 'publicfiles');
        self::assertSame(RequestClass::PublicDownload, $download);

        $this->expectException(\InvalidArgumentException::class);
        RequestClass::resolve('download');
    }

    #[DataProvider('deprecatedClasses')]
    public function testLegacyClassesStayAddressable(RequestClass $class): void
    {
        self::assertTrue($class->isDeprecated());
        self::assertNotSame('', $class->summary());
    }

    /**
     * @return list<array{0: RequestClass}>
     */
    public static function deprecatedClasses(): array
    {
        return [
            [RequestClass::Tle],
            [RequestClass::TleLatest],
            [RequestClass::TlePublish],
            [RequestClass::Omm],
            [RequestClass::Csm],
        ];
    }
}
