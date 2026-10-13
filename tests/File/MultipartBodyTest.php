<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\File;

use SpacedTracker\File\MultipartBody;
use PHPUnit\Framework\TestCase;

final class MultipartBodyTest extends TestCase
{
    public function testBuildsASingleFilePartAndStripsUnsafeFilenameCharacters(): void
    {
        $part = MultipartBody::singleFile('file', "notes\"\r\n\\.txt", 'hello', 'text/plain');

        self::assertStringStartsWith('multipart/form-data; boundary=spacedtracker', $part['contentType']);
        self::assertStringContainsString('name="file"; filename="notes.txt"', $part['body']);
        self::assertStringContainsString('Content-Type: text/plain', $part['body']);
        self::assertStringContainsString('hello', $part['body']);
        self::assertStringNotContainsString("\n\\", $part['body']);
    }

    public function testRejectsFieldNamesAndContentTypesThatCouldBreakThePart(): void
    {
        foreach (
            [
            static fn () => MultipartBody::singleFile('', 'a.txt', 'x', 'text/plain'),
            static fn () => MultipartBody::singleFile('bad name', 'a.txt', 'x', 'text/plain'),
            static fn () => MultipartBody::singleFile('file', 'a.txt', 'x', ''),
            static fn () => MultipartBody::singleFile('file', 'a.txt', 'x', "text/plain\r\nX-Evil: 1"),
            ] as $case
        ) {
            try {
                $case();
                self::fail('The multipart part must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
