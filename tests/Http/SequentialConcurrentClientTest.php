<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Http;

use SpacedTracker\Exception\TransportException;
use SpacedTracker\Http\SequentialConcurrentClient;
use SpacedTracker\Testing\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class SequentialConcurrentClientTest extends TestCase
{
    public function testPreservesKeysAndKeepsAResponseAttachedToAClientException(): void
    {
        $factory = new Psr17Factory();
        $recovered = $factory->createResponse(200)->withBody($factory->createStream('kept'));
        $http = new MockHttpClient([
            $factory->createResponse(201)->withBody($factory->createStream('first')),
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
        $client = new SequentialConcurrentClient($http);

        $responses = $client->send([
            'a' => $factory->createRequest('GET', 'https://example.test/a'),
            'b' => $factory->createRequest('GET', 'https://example.test/b'),
        ]);

        self::assertSame('first', (string) $responses['a']->getBody());
        self::assertSame('kept', (string) $responses['b']->getBody());
        self::assertSame([], $client->send([]));
    }

    public function testAClientExceptionWithoutAResponseFailsTheBatch(): void
    {
        $factory = new Psr17Factory();
        $http = new MockHttpClient([
            static function (): ResponseInterface {
                throw new class ('down') extends RuntimeException implements ClientExceptionInterface {
                };
            },
        ]);

        $this->expectException(TransportException::class);
        (new SequentialConcurrentClient($http))->send([
            'a' => $factory->createRequest('GET', 'https://example.test/a'),
        ]);
    }

    public function testAClientExceptionWhoseResponseIsNotAMessageFailsTheBatch(): void
    {
        $factory = new Psr17Factory();
        $http = new MockHttpClient([
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
        (new SequentialConcurrentClient($http))->send([
            'a' => $factory->createRequest('GET', 'https://example.test/a'),
        ]);
    }
}
