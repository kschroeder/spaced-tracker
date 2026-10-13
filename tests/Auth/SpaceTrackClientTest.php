<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Auth;

use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Exception\AuthenticationException;
use SpacedTracker\Exception\QueryException;
use SpacedTracker\Query\Query;
use SpacedTracker\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

final class SpaceTrackClientTest extends TestCase
{
    public function testLoginThenQuerySendsTheSessionCookieAndNeverLogsThePassword(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, (string) json_encode([['NORAD_CAT_ID' => 25544, 'OBJECT_NAME' => 'ISS (ZARYA)']])),
        ]);

        $result = $stack['client']->execute(
            Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1),
        );

        self::assertSame(25544, $result->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertCount(2, $stack['http']->requests);
        $query = $stack['http']->requests[1];
        self::assertStringContainsString('/basicspacedata/query/class/gp/NORAD_CAT_ID/25544/', (string) $query->getUri());
        self::assertSame('chocolatechip=session-token', $query->getHeaderLine('Cookie'));
        self::assertStringNotContainsString('correct-horse-battery', (string) json_encode($stack['logger']->records));
        self::assertTrue($stack['client']->hasSession());
    }

    public function testLoginBodyContainsCredentialsButFailureTextIsRedacted(): void
    {
        $stack = Factory::client([
            Factory::response(200, 'identity=ada@example.com&password=correct-horse-battery'),
        ]);

        try {
            $stack['client']->login();
            self::fail('Login without a cookie must fail.');
        } catch (AuthenticationException $exception) {
            self::assertStringNotContainsString('correct-horse-battery', $exception->getMessage());
        }

        $login = $stack['http']->requests[0];
        self::assertStringContainsString('password=correct-horse-battery', (string) $login->getBody());
        self::assertStringNotContainsString('correct-horse-battery', (string) json_encode($stack['logger']->records));
    }

    public function testExpiredSessionIsRefreshedOnceAfter401(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::response(401, 'expired'),
            Factory::loginResponse(),
            Factory::response(200, '[]'),
        ]);

        $result = $stack['client']->execute(Query::create(RequestClass::Boxscore)->limit(1));

        self::assertSame(200, $result->statusCode);
        self::assertCount(4, $stack['http']->requests);
    }

    public function testHttpErrorsBecomeQueryExceptionsWithoutResponseSecrets(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::response(500, 'password=correct-horse-battery boom'),
        ]);

        try {
            $stack['client']->execute(Query::create(RequestClass::Announcement)->limit(1));
            self::fail('A 500 response must throw.');
        } catch (QueryException $exception) {
            self::assertSame(500, $exception->statusCode);
            self::assertStringNotContainsString('correct-horse-battery', $exception->getMessage());
        }
    }

    public function testBatchPreservesKeysAndUploadsMultipartWithoutPuttingThePasswordInThePath(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544}]'),
            Factory::response(200, '[{"NORAD_CAT_ID":20580}]'),
            Factory::response(200, '{"ok":true}'),
        ]);

        $results = $stack['client']->executeMany([
            'iss' => Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1)->format(OutputFormat::Json),
            'hst' => Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 20580)->limit(1),
        ]);
        $upload = $stack['client']->uploadFile('10', 'elements.txt', 'hello', 'text/plain');

        self::assertArrayHasKey('iss', $results);
        self::assertArrayHasKey('hst', $results);
        self::assertSame(25544, $results['iss']->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertSame(200, $upload->statusCode);
        $uploadRequest = $stack['http']->requests[3];
        self::assertSame('POST', $uploadRequest->getMethod());
        self::assertStringContainsString('multipart/form-data', $uploadRequest->getHeaderLine('Content-Type'));
        self::assertStringContainsString('elements.txt', (string) $uploadRequest->getBody());
        self::assertStringNotContainsString('correct-horse-battery', (string) $uploadRequest->getUri());
    }

    public function testLogoutClearsTheSessionAndModelDefinitionsAreCached(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, '{"data":[{"Field":"NORAD_CAT_ID"}]}'),
            Factory::response(200, ''),
        ]);

        $first = $stack['client']->modelDefinition('gp');
        $second = $stack['client']->modelDefinition(RequestClass::Gp->wireName(), 'basicspacedata');
        $stack['client']->logout();

        self::assertSame($first, $second);
        self::assertFalse($stack['client']->hasSession());
        self::assertCount(3, $stack['http']->requests);
        self::assertSame('GET', $stack['http']->requests[2]->getMethod());
        self::assertStringContainsString('/ajaxauth/logout', (string) $stack['http']->requests[2]->getUri());
    }

    public function testSendAuthenticatedRefusesAForeignHost(): void
    {
        $stack = Factory::client([]);
        $request = (new \Nyholm\Psr7\Factory\Psr17Factory())->createRequest('GET', 'https://evil.example/steal');

        $this->expectException(\InvalidArgumentException::class);
        $stack['client']->sendAuthenticated($request);
    }
}
