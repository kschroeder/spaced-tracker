<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Auth;

use SpacedTracker\Auth\Session;
use SpacedTracker\Auth\SessionStoreInterface;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Config\ClientConfig;
use SpacedTracker\Exception\DecodeException;
use SpacedTracker\Exception\QueryException;
use SpacedTracker\Exception\RateLimitException;
use SpacedTracker\Exception\TransportException;
use SpacedTracker\Http\ConcurrentClientInterface;
use SpacedTracker\Query\Query;
use SpacedTracker\Tests\Support\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class SpaceTrackClientBehaviorTest extends TestCase
{
    public function testAnEmptyBatchDoesNotLogIn(): void
    {
        $stack = Factory::client([]);

        self::assertSame([], $stack['client']->executeMany([]));
        self::assertSame([], $stack['http']->requests);
        self::assertFalse($stack['client']->hasSession());
        self::assertNull($stack['client']->sessionExpiresAt());
    }

    public function testABatchRetriesOnlyTheUnauthorizedQueries(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::response(401, 'expired'),
            Factory::response(200, '[{"NORAD_CAT_ID":20580}]'),
            Factory::loginResponse(),
            Factory::response(200, '[{"NORAD_CAT_ID":25544}]'),
        ]);

        $results = $stack['client']->executeMany([
            'iss' => Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1),
            'hst' => Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 20580)->limit(1),
        ]);

        self::assertSame(25544, $results['iss']->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertSame(20580, $results['hst']->records[0]['NORAD_CAT_ID'] ?? null);
        self::assertTrue($stack['logger']->contains('spacetrack.auth.refresh'));
        self::assertCount(5, $stack['http']->requests);
        self::assertStringNotContainsString('correct-horse-battery', (string) json_encode($stack['logger']->records));
    }

    public function testADroppedBatchResponseFailsClosed(): void
    {
        $stack = Factory::client(
            [Factory::loginResponse()],
            concurrent: new class implements ConcurrentClientInterface {
                public function send(array $requests): array
                {
                    unset($requests);

                    return [];
                }
            },
        );

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('dropped a Space-Track response');
        $stack['client']->executeMany([
            'iss' => Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1),
        ]);
    }

    public function testModelDefinitionsRejectMalformedJsonAndAcceptAnExplicitCustomClass(): void
    {
        $invalid = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, '<html>'),
        ]);
        try {
            $invalid['client']->modelDefinition('gp');
            self::fail('Invalid model JSON must fail.');
        } catch (DecodeException $exception) {
            self::assertStringContainsString('not valid JSON', $exception->getMessage());
        }

        $nullBody = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, 'null'),
        ]);
        try {
            $nullBody['client']->modelDefinition('gp');
            self::fail('A JSON null model must fail.');
        } catch (DecodeException $exception) {
            self::assertStringContainsString('not a JSON object or list', $exception->getMessage());
        }

        $custom = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, '{"data":[]}'),
        ]);
        $definition = $custom['client']->modelDefinition('future_class', 'basicspacedata');
        self::assertSame([], $definition['data']);
        self::assertStringContainsString(
            '/basicspacedata/modeldef/class/future_class/format/json',
            (string) $custom['http']->requests[1]->getUri(),
        );

        $unknown = Factory::client([]);
        try {
            $unknown['client']->modelDefinition('future_class');
            self::fail('An unknown class needs a controller.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('future_class', $exception->getMessage());
        }
        try {
            $unknown['client']->modelDefinition('future-class', 'not a controller');
            self::fail('Unsafe class tokens must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $unknown['http']->requests);
        }
    }

    public function testUploadsRejectUnsafeNamesAndKeepANonJsonBody(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, 'plain text result'),
        ]);
        $rejected = 0;
        foreach ([['bad id', 'notes.txt'], ['10', '.'], ['10', '..'], ['10', '///']] as [$folder, $name]) {
            try {
                $stack['client']->uploadFile($folder, $name, 'hello');
                self::fail('Unsafe upload names must be rejected.');
            } catch (\InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(4, $rejected);
        self::assertSame([], $stack['http']->requests);

        $result = $stack['client']->uploadFile('Folder_1', "a/\0b\\c.txt", 'hello');

        self::assertSame(200, $result->statusCode);
        self::assertSame('plain text result', $result->rawBody);
        self::assertSame([], $result->records);
        $body = (string) $stack['http']->requests[1]->getBody();
        self::assertStringContainsString('filename="abc.txt"', $body);
        self::assertStringNotContainsString('a/b', $body);
        self::assertStringNotContainsString('correct-horse-battery', (string) $stack['http']->requests[1]->getUri());
    }

    public function testTransportFailuresAreRecoveredOnlyWhenTheyCarryAResponse(): void
    {
        $down = Factory::client([
            Factory::loginResponse(),
            static function (): ResponseInterface {
                throw new class ('down') extends RuntimeException implements ClientExceptionInterface {
                };
            },
        ]);
        try {
            $down['client']->execute(Query::create(RequestClass::Gp)->limit(1));
            self::fail('A transport failure must throw.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('Space-Track request failed', $exception->getMessage());
            self::assertTrue($down['logger']->contains('spacetrack.http.transport_error'));
            self::assertFalse($down['logger']->contains('correct-horse-battery'));
        }

        $recovered = Factory::response(200, '[]');
        $stack = Factory::client([
            Factory::loginResponse(),
            static function () use ($recovered): ResponseInterface {
                throw new class ($recovered) extends RuntimeException implements ClientExceptionInterface {
                    public function __construct(private ResponseInterface $response)
                    {
                        parent::__construct('recovered');
                    }

                    public function getResponse(): ResponseInterface
                    {
                        return $this->response;
                    }
                };
            },
        ]);
        self::assertSame(200, $stack['client']->execute(Query::create(RequestClass::Boxscore)->limit(1))->statusCode);

        $notAResponse = Factory::client([
            Factory::loginResponse(),
            static function (): ResponseInterface {
                throw new class ('down') extends RuntimeException implements ClientExceptionInterface {
                    public function getResponse(): string
                    {
                        return 'nope';
                    }
                };
            },
        ]);
        $this->expectException(TransportException::class);
        $notAResponse['client']->execute(Query::create(RequestClass::Gp)->limit(1));
    }

    public function testSessionExpiryIsVisibleAndForcesAnotherLogin(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::loginResponse(),
            Factory::response(200, '[]'),
        ]);

        self::assertFalse($stack['client']->hasSession());
        self::assertNull($stack['client']->sessionExpiresAt());
        $stack['client']->login();
        $expires = $stack['client']->sessionExpiresAt();

        self::assertTrue($stack['client']->hasSession());
        self::assertEquals(new \DateTimeImmutable('2026-01-01T02:00:00Z'), $expires);
        $stack['clock']->advance(7201);
        self::assertFalse($stack['client']->hasSession());
        self::assertEquals($expires, $stack['client']->sessionExpiresAt());

        $stack['client']->execute(Query::create(RequestClass::Boxscore)->limit(1));

        self::assertCount(3, $stack['http']->requests);
        self::assertTrue($stack['client']->hasSession());
    }

    public function testAStoreThatDropsTheSessionFailsClosed(): void
    {
        $stack = Factory::client(
            [Factory::loginResponse(), Factory::loginResponse()],
            sessions: new class implements SessionStoreInterface {
                public function get(): ?Session
                {
                    return null;
                }

                public function put(Session $session): void
                {
                    unset($session);
                }

                public function clear(): void
                {
                }
            },
        );
        $stack['client']->login();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('did not store a session');
        $stack['client']->execute(Query::create(RequestClass::Boxscore)->limit(1));
    }

    public function testRateLimitsLongErrorsAndDecodeFailuresStayFreeOfSecrets(): void
    {
        $limited = Factory::client([
            Factory::loginResponse(),
            Factory::response(429, 'slow down', ['X-RateLimit-Remaining' => '0']),
        ]);
        try {
            $limited['client']->execute(Query::create(RequestClass::Gp)->limit(1));
            self::fail('HTTP 429 must throw.');
        } catch (RateLimitException $exception) {
            self::assertStringContainsString('rate limit', $exception->getMessage());
            self::assertFalse($limited['logger']->contains('correct-horse-battery'));
        }

        $long = str_repeat('A', 200);
        $snippet = Factory::client([
            Factory::loginResponse(),
            Factory::response(500, 'password=correct-horse-battery ' . $long),
        ]);
        try {
            $snippet['client']->execute(Query::create(RequestClass::Announcement)->limit(1));
            self::fail('HTTP 500 must throw.');
        } catch (QueryException $exception) {
            self::assertStringNotContainsString('correct-horse-battery', $exception->getMessage());
            self::assertStringContainsString('…', $exception->getMessage());
            self::assertLessThan(strlen($long), substr_count($exception->getMessage(), 'A'));
        }

        $decoded = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, '{'),
        ]);
        try {
            $decoded['client']->execute(Query::create(RequestClass::Gp)->limit(1));
            self::fail('Invalid JSON must throw.');
        } catch (DecodeException) {
            self::assertTrue($decoded['logger']->contains('spacetrack.query.decode_failed'));
        }

        $rated = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, '[]', ['X-RateLimit-Remaining' => '3']),
        ]);
        $rated['client']->execute(Query::create(RequestClass::Boxscore)->limit(1));
        self::assertTrue($rated['logger']->contains('X-RateLimit-Remaining'));
        self::assertFalse($rated['logger']->contains('correct-horse-battery'));
    }

    public function testAuthenticatedRequestsStayOnTheHostAndDropHopByHopHeaders(): void
    {
        $stack = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, '[]'),
            Factory::response(200, '{"ok":true}'),
        ]);
        $factory = new Psr17Factory();
        $relative = $stack['client']->sendAuthenticated(
            $factory->createRequest('GET', '/basicspacedata/query/class/boxscore'),
        );
        $absolute = $stack['client']->sendAuthenticated(
            $factory->createRequest('POST', 'https://www.space-track.org/fileshare/query/class/upload?folder_id=10')
                ->withHeader('X-Trace', 'keep')
                ->withHeader('Authorization', 'Bearer secret-token')
                ->withHeader('Cookie', 'evil=1')
                ->withHeader('Connection', 'close')
                ->withBody($factory->createStream('payload')),
        );

        self::assertSame(200, $relative->getStatusCode());
        self::assertSame(200, $absolute->getStatusCode());
        self::assertSame(
            'https://www.space-track.org/basicspacedata/query/class/boxscore',
            (string) $stack['http']->requests[1]->getUri(),
        );
        $forwarded = $stack['http']->requests[2];
        self::assertStringContainsString('folder_id=10', (string) $forwarded->getUri());
        self::assertSame('keep', $forwarded->getHeaderLine('X-Trace'));
        self::assertSame('', $forwarded->getHeaderLine('Authorization'));
        self::assertSame('', $forwarded->getHeaderLine('Connection'));
        self::assertSame('chocolatechip=session-token', $forwarded->getHeaderLine('Cookie'));
        self::assertSame('payload', (string) $forwarded->getBody());
    }

    public function testPredicatePathsAreLoggedOnlyWhenEnabledAndRemainRedacted(): void
    {
        $quiet = Factory::client([
            Factory::loginResponse(),
            Factory::response(200, '[]'),
        ]);
        $quiet['client']->execute(Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1));
        self::assertFalse($this->loggedPath($quiet['logger']->records));

        $loud = Factory::client(
            [Factory::loginResponse(), Factory::response(200, '[]')],
            config: new ClientConfig(logPredicateValues: true),
        );
        $loud['client']->execute(Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1));

        self::assertTrue($this->loggedPath($loud['logger']->records));
        self::assertTrue($loud['logger']->contains('25544'));
        self::assertFalse($loud['logger']->contains('correct-horse-battery'));
    }

    /**
     * @param list<array{level: mixed, message: string, context: array<mixed>}> $records
     */
    private function loggedPath(array $records): bool
    {
        foreach ($records as $record) {
            if (isset($record['context']['path'])) {
                return true;
            }
        }

        return false;
    }
}
