<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Auth;

use SpacedTracker\Auth\CookieParser;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class CookieParserTest extends TestCase
{
    public function testParsesExpiryDeletesAndSkipsMalformedHeaders(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withAddedHeader('Set-Cookie', 'chocolatechip=first; Path=/; HttpOnly; Max-Age=100')
            ->withAddedHeader('Set-Cookie', 'other=keep; Expires=Wed, 21 Oct 2026 07:28:00 GMT')
            ->withAddedHeader('Set-Cookie', 'chocolatechip=; Max-Age=0')
            ->withAddedHeader('Set-Cookie', 'broken')
            ->withAddedHeader('Set-Cookie', '=missing-name');

        $parsed = (new CookieParser())->parse($response, $now, 7200);

        self::assertSame(['other' => 'keep'], $parsed['cookies']);
        self::assertEquals($now->modify('+100 seconds'), $parsed['expiresAt']);
    }

    public function testMaxAgeWinsOverExpiresAndZeroMeansNow(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withHeader('Set-Cookie', 'chocolatechip=token; Expires=Wed, 21 Oct 2026 07:28:00 GMT; Max-Age=0; Secure');

        $parsed = (new CookieParser())->parse($response, $now, 7200);

        self::assertSame(['chocolatechip' => 'token'], $parsed['cookies']);
        self::assertEquals($now, $parsed['expiresAt']);
    }

    public function testUsesTheDefaultTtlWhenNoExpiryIsPresent(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withHeader('Set-Cookie', 'chocolatechip=token; Max-Age=later; Expires=not-a-date');

        $parsed = (new CookieParser())->parse($response, $now, 90);

        self::assertEquals($now->modify('+90 seconds'), $parsed['expiresAt']);
    }

    public function testTheEarliestExpiryWins(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withAddedHeader('Set-Cookie', 'one=a; Max-Age=500')
            ->withAddedHeader('Set-Cookie', 'two=b; Max-Age=50')
            ->withAddedHeader('Set-Cookie', 'three=c; Max-Age=-5');

        $parsed = (new CookieParser())->parse($response, $now, 7200);

        self::assertEquals($now, $parsed['expiresAt']);
        self::assertArrayHasKey('one', $parsed['cookies']);
        self::assertArrayHasKey('three', $parsed['cookies']);
    }
}
