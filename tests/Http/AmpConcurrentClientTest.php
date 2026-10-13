<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Http;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request as AmpRequest;
use Amp\Http\Client\Response as AmpResponse;
use SpacedTracker\Exception\TransportException;
use SpacedTracker\Http\AmpConcurrentClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class AmpConcurrentClientTest extends TestCase
{
    public function testPreservesKeysHeadersAndBodiesFromTheDelegate(): void
    {
        $delegate = new class implements DelegateHttpClient {
            /** @var list<array{method: string, uri: string}> */
            public array $seen = [];

            public function request(AmpRequest $request, Cancellation $cancellation): AmpResponse
            {
                unset($cancellation);
                $this->seen[] = [
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                ];

                return new AmpResponse(
                    '1.1',
                    201,
                    'Created',
                    ['content-type' => ['application/json'], 'x-trace' => ['catalog']],
                    '{"ok":true}',
                    $request,
                );
            }
        };
        $factory = new Psr17Factory();
        $client = new AmpConcurrentClient(new HttpClient($delegate, []), $factory, $factory);

        $responses = $client->send([
            'post' => $factory->createRequest('POST', 'https://example.test/gp')
                ->withHeader('X-Trace', '1')
                ->withBody($factory->createStream('hello')),
            'get' => $factory->createRequest('GET', 'https://example.test/satcat'),
        ]);

        self::assertSame(201, $responses['post']->getStatusCode());
        self::assertSame('catalog', $responses['post']->getHeaderLine('X-Trace'));
        self::assertSame('{"ok":true}', (string) $responses['post']->getBody());
        self::assertSame('POST', $delegate->seen[0]['method']);
        self::assertSame('https://example.test/gp', $delegate->seen[0]['uri']);
        self::assertSame('GET', $delegate->seen[1]['method']);
        self::assertSame([], $client->send([]));
    }

    public function testAnEmptyMethodFailsBeforeTheDelegateRuns(): void
    {
        $delegate = new class implements DelegateHttpClient {
            public bool $called = false;

            public function request(AmpRequest $request, Cancellation $cancellation): AmpResponse
            {
                unset($request, $cancellation);
                $this->called = true;

                throw new HttpException('should not run');
            }
        };
        $factory = new Psr17Factory();
        $client = new AmpConcurrentClient(new HttpClient($delegate, []), $factory, $factory);

        try {
            $client->send([
                'bad' => $factory->createRequest('GET', 'https://example.test/gp')->withMethod(''),
            ]);
            self::fail('An empty method must fail the batch.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('failed', $exception->getMessage());
            self::assertInstanceOf(TransportException::class, $exception->getPrevious());
            self::assertStringContainsString('HTTP method', $exception->getPrevious()->getMessage());
        }
        self::assertFalse($delegate->called);
    }

    public function testAnHttpExceptionBecomesATransportException(): void
    {
        $delegate = new class implements DelegateHttpClient {
            public function request(AmpRequest $request, Cancellation $cancellation): AmpResponse
            {
                unset($request, $cancellation);

                throw new HttpException('socket down');
            }
        };
        $factory = new Psr17Factory();
        $client = new AmpConcurrentClient(new HttpClient($delegate, []), $factory, $factory);

        try {
            $client->send([
                'down' => $factory->createRequest('GET', 'https://example.test/down'),
            ]);
            self::fail('An Amp HTTP failure must fail the batch.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('failed', $exception->getMessage());
            self::assertInstanceOf(\Throwable::class, $exception->getPrevious());
        }
    }
}
