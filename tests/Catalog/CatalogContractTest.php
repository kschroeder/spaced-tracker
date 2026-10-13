<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Catalog;

use SpacedTracker\Catalog\Controller;
use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;
use PHPUnit\Framework\TestCase;

final class CatalogContractTest extends TestCase
{
    public function testEveryClassHasASummaryMethodAndControllerLabel(): void
    {
        $directory = RequestClass::directory();

        self::assertCount(count(RequestClass::cases()), $directory);
        foreach (RequestClass::cases() as $index => $class) {
            self::assertSame($class->value, $directory[$index]['id']);
            self::assertSame($class->wireName(), $directory[$index]['class']);
            self::assertSame($class->controller()->value, $directory[$index]['controller']);
            self::assertNotSame('', $class->summary());
            self::assertNotSame('', $class->controller()->label());
            self::assertSame(
                $class === RequestClass::FileShareUpload ? 'POST' : 'GET',
                $class->httpMethod(),
            );
        }

        self::assertSame(['name'], RequestClass::PublicDownload->queryParameters());
        self::assertSame([], RequestClass::Gp->queryParameters());
    }

    public function testResolveReportsUnknownAndMismatchedControllers(): void
    {
        self::assertSame(RequestClass::Gp, RequestClass::resolve('GP', 'basicspacedata'));

        try {
            RequestClass::resolve('not_a_class');
            self::fail('Unknown classes must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Unknown', $exception->getMessage());
        }

        try {
            RequestClass::resolve('download', 'basicspacedata');
            self::fail('A controller that does not own the class must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('does not provide', $exception->getMessage());
        }
    }

    public function testOnlyRowFormatsAreStructured(): void
    {
        foreach (OutputFormat::cases() as $format) {
            $structured = in_array($format, [OutputFormat::Json, OutputFormat::Csv, OutputFormat::Tle, OutputFormat::ThreeLine], true);
            self::assertSame($structured, $format->isStructured());
        }

        $labels = array_map(static fn (Controller $controller): string => $controller->label(), Controller::cases());
        self::assertContains('Basic space data', $labels);
        self::assertContains('Expanded space data', $labels);
        self::assertContains('File share', $labels);
        self::assertContains('Special perturbations ephemeris', $labels);
        self::assertContains('Public files', $labels);
    }
}
