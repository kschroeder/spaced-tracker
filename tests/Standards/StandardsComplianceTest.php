<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Standards;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class StandardsComplianceTest extends TestCase
{
    public function testLibraryPhpFilesDeclareStrictTypesAndMatchPsr4(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $seen = 0;

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $seen++;
            $contents = (string) file_get_contents($file->getPathname());
            self::assertMatchesRegularExpression(
                '/\A<\?php\R\Rdeclare\(strict_types=1\);/',
                $contents,
                $file->getPathname() . ' must start with a strict types declaration.',
            );
            self::assertDoesNotMatchRegularExpression(
                '/\b(eval|var_dump|var_export|dump|dd|print_r)\s*\(/',
                $contents,
                $file->getPathname() . ' contains a debug call.',
            );

            if (preg_match('/^namespace ([^;]+);/m', $contents, $namespace) !== 1) {
                continue;
            }
            if (preg_match('/^(?:final |readonly |abstract )*(?:class|enum|interface) ([A-Za-z0-9_]+)/m', $contents, $type) !== 1) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $expected = 'SpacedTracker\\' . str_replace('/', '\\', substr($relative, 0, -4));
            self::assertSame($expected, $namespace[1] . '\\' . $type[1], $file->getPathname());
        }

        self::assertGreaterThan(40, $seen);
    }
}
