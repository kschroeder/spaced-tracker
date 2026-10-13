<?php

declare(strict_types=1);

namespace SpacedTracker\Http;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request as AmpRequest;
use SpacedTracker\Exception\TransportException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function Amp\async;
use function Amp\Future\awaitAll;

/**
 * Runs a batch on an Amp HTTP client.
 *
 * Select this explicitly. Amp drives its own event loop, which can nest
 * badly inside an application that already runs one.
 */
final class AmpConcurrentClient implements ConcurrentClientInterface
{
    public function __construct(
        private readonly HttpClient $client,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function send(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $futures = [];
        foreach ($requests as $key => $request) {
            $futures[$key] = async(function () use ($request): \Psr\Http\Message\ResponseInterface {
                $method = $request->getMethod();
                if ($method === '') {
                    throw new TransportException('A Space-Track request needs an HTTP method.');
                }
                $amp = new AmpRequest((string) $request->getUri(), $method);
                foreach ($request->getHeaders() as $name => $values) {
                    $headerName = (string) $name;
                    if ($headerName === '') {
                        continue;
                    }
                    $amp->setHeader($headerName, implode(', ', $values));
                }
                $body = (string) $request->getBody();
                if ($body !== '') {
                    $amp->setBody($body);
                }

                try {
                    $response = $this->client->request($amp);
                    $payload = $response->getBody()->buffer();
                } catch (HttpException $exception) {
                    throw new TransportException('A concurrent Space-Track request failed.', 0, $exception);
                }

                $psr = $this->responses->createResponse($response->getStatus());
                foreach ($response->getHeaders() as $name => $values) {
                    $psr = $psr->withHeader($name, $values);
                }

                return $psr->withBody($this->streams->createStream($payload));
            });
        }

        [$errors, $values] = awaitAll($futures);
        if ($errors !== []) {
            $first = reset($errors);
            throw new TransportException('A concurrent Space-Track request failed.', 0, $first);
        }

        /** @var array<int|string, \Psr\Http\Message\ResponseInterface> $values */
        return $values;
    }
}
