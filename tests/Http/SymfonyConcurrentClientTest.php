<?php

declare(strict_types=1);

namespace SpacedTracker\Tests\Http;

use SpacedTracker\Exception\TransportException;
use SpacedTracker\Http\SymfonyConcurrentClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class SymfonyConcurrentClientTest extends TestCase
{
    public function testMultiplexesMockResponsesAndCopiesHeaders(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$method, $url, $options['body'] ?? null];

            return new MockResponse('alpha', [
                'http_code' => 201,
                'response_headers' => ['Content-Type: application/json', 'X-Trace: catalog'],
            ]);
        });
        $factory = new Psr17Factory();
        $client = new SymfonyConcurrentClient($mock, $factory, $factory);

        $responses = $client->send([
            'one' => $factory->createRequest('POST', 'https://example.test/one')->withBody($factory->createStream('payload')),
        ]);

        self::assertSame(201, $responses['one']->getStatusCode());
        self::assertSame('alpha', (string) $responses['one']->getBody());
        self::assertSame('catalog', $responses['one']->getHeaderLine('X-Trace'));
        self::assertSame('application/json', $responses['one']->getHeaderLine('Content-Type'));
        self::assertSame('POST', $seen[0][0]);
        self::assertStringContainsString('/one', $seen[0][1]);
        self::assertSame('payload', $seen[0][2]);
        self::assertSame([], $client->send([]));
    }

    public function testASymfonyTransportFailureIsWrapped(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('', ['error' => 'connection refused']),
        ]);
        $factory = new Psr17Factory();
        $client = new SymfonyConcurrentClient($mock, $factory, $factory);

        $this->expectException(TransportException::class);
        $client->send([
            'down' => $factory->createRequest('GET', 'https://example.test/down'),
        ]);
    }

    public function testATimeoutChunkFailsTheBatch(): void
    {
        $factory = new Psr17Factory();
        $client = new SymfonyConcurrentClient($this->timeoutClient(), $factory, $factory);

        try {
            $client->send([
                'slow' => $factory->createRequest('GET', 'https://example.test/slow'),
            ]);
            self::fail('A timeout chunk must fail the batch.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('timed out', $exception->getMessage());
        }
    }

    private function timeoutClient(): HttpClientInterface
    {
        return new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                unset($method, $url, $options);

                return new MockResponse('unused');
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                unset($timeout);
                $response = $responses instanceof ResponseInterface ? $responses : null;
                if ($response === null) {
                    foreach ($responses as $candidate) {
                        $response = $candidate;
                        break;
                    }
                }
                Assert::assertInstanceOf(ResponseInterface::class, $response);

                return new class ($response) implements ResponseStreamInterface {
                    private int $position = 0;

                    public function __construct(private ResponseInterface $response)
                    {
                    }

                    public function key(): ResponseInterface
                    {
                        return $this->response;
                    }

                    public function current(): ChunkInterface
                    {
                        return new class implements ChunkInterface {
                            public function isTimeout(): bool
                            {
                                return true;
                            }

                            public function isFirst(): bool
                            {
                                return false;
                            }

                            public function isLast(): bool
                            {
                                return false;
                            }

                            public function getInformationalStatus(): ?array
                            {
                                return null;
                            }

                            public function getContent(): string
                            {
                                return '';
                            }

                            public function getOffset(): int
                            {
                                return 0;
                            }

                            public function getError(): ?string
                            {
                                return null;
                            }
                        };
                    }

                    public function next(): void
                    {
                        $this->position++;
                    }

                    public function rewind(): void
                    {
                        $this->position = 0;
                    }

                    public function valid(): bool
                    {
                        return $this->position === 0;
                    }
                };
            }

            public function withOptions(array $options): static
            {
                unset($options);

                return $this;
            }
        };
    }
}
