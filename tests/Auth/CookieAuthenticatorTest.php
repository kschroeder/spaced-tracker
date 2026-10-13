<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Auth;

use SpacedTracker\Auth\CookieAuthenticator;
use SpacedTracker\Auth\Credentials;
use SpacedTracker\Auth\Session;
use SpacedTracker\Config\ClientConfig;
use SpacedTracker\Exception\AuthenticationException;
use SpacedTracker\Exception\TransportException;
use SpacedTracker\Testing\MockHttpClient;
use SpacedTracker\Tests\Support\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class CookieAuthenticatorTest extends TestCase
{
    public function testALongLoginFailureIsTruncatedAndRedacted(): void
    {
        $http = new MockHttpClient([
            Factory::response(401, 'password=correct-horse-battery ' . str_repeat('A', 220)),
        ]);
        $authenticator = $this->authenticator($http);

        try {
            $authenticator->login(new Credentials('ada@example.com', 'correct-horse-battery'));
            self::fail('A login without a cookie must fail.');
        } catch (AuthenticationException $exception) {
            self::assertStringNotContainsString('correct-horse-battery', $exception->getMessage());
            self::assertStringContainsString('…', $exception->getMessage());
            self::assertLessThan(220, substr_count($exception->getMessage(), 'A'));
            self::assertStringContainsString('[redacted]', $exception->getMessage());
        }

        $empty = new MockHttpClient([Factory::response(200, '   ')]);
        try {
            $this->authenticator($empty)->login(new Credentials('ada@example.com', 'correct-horse-battery'));
            self::fail('A login without a cookie must fail.');
        } catch (AuthenticationException $exception) {
            self::assertStringContainsString('No session cookie', $exception->getMessage());
        }
    }

    public function testLoginTransportFailuresDoNotExposeThePassword(): void
    {
        $http = new MockHttpClient([
            static function (): ResponseInterface {
                throw new class ('down') extends RuntimeException implements ClientExceptionInterface {
                };
            },
        ]);
        $authenticator = $this->authenticator($http);

        try {
            $authenticator->login(new Credentials('ada@example.com', 'correct-horse-battery'));
            self::fail('A failed login request must throw.');
        } catch (TransportException $exception) {
            self::assertStringNotContainsString('correct-horse-battery', $exception->getMessage());
        }
    }

    public function testLogoutIgnoresATransportFailure(): void
    {
        $http = new MockHttpClient([
            static function (): ResponseInterface {
                throw new class ('down') extends RuntimeException implements ClientExceptionInterface {
                };
            },
        ]);
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->authenticator($http)->logout(new Session(
            ['chocolatechip' => 'session-token'],
            $now,
            $now->modify('+1 hour'),
        ));

        self::assertCount(1, $http->requests);
        self::assertStringContainsString('/ajaxauth/logout', (string) $http->requests[0]->getUri());
        self::assertSame('chocolatechip=session-token', $http->requests[0]->getHeaderLine('Cookie'));
    }

    private function authenticator(MockHttpClient $http): CookieAuthenticator
    {
        $factory = new Psr17Factory();

        return new CookieAuthenticator($http, $factory, $factory, Factory::clock(), new ClientConfig());
    }
}
