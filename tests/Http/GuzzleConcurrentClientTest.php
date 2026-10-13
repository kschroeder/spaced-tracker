<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Http;

use SpacedTracker\Exception\TransportException;
use SpacedTracker\Http\GuzzleConcurrentClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class GuzzleConcurrentClientTest extends TestCase
{
    public function testSettlesFulfilledResponsesUnderTheirOriginalKeys(): void
    {
        $mock = new MockHandler([
            new Response(200, ['X-Trace' => 'alpha'], 'alpha'),
            new Response(204, [], ''),
        ]);
        $client = new GuzzleConcurrentClient(new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]));
        $factory = new Psr17Factory();

        $responses = $client->send([
            'alpha' => $factory->createRequest('POST', 'https://example.test/alpha')->withBody($factory->createStream('payload')),
            'beta' => $factory->createRequest('GET', 'https://example.test/beta'),
        ]);

        self::assertSame('alpha', (string) $responses['alpha']->getBody());
        self::assertSame('alpha', $responses['alpha']->getHeaderLine('X-Trace'));
        self::assertSame(204, $responses['beta']->getStatusCode());
        self::assertSame([], $client->send([]));
    }

    public function testARejectedTransferBecomesATransportException(): void
    {
        $mock = new MockHandler([
            new ConnectException('connection refused', new Request('GET', 'https://example.test/down')),
        ]);
        $client = new GuzzleConcurrentClient(new Client(['handler' => HandlerStack::create($mock)]));
        $factory = new Psr17Factory();

        try {
            $client->send([
                'down' => $factory->createRequest('GET', 'https://example.test/down'),
            ]);
            self::fail('A rejected Guzzle transfer must fail the batch.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('failed', $exception->getMessage());
            self::assertInstanceOf(ConnectException::class, $exception->getPrevious());
        }
    }
}
