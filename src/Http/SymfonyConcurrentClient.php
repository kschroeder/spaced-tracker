<?php

declare(strict_types=1);

namespace SpacedTracker\Http;

use SpacedTracker\Exception\TransportException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\Exception\TransportException as SymfonyTransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;

/**
 * Multiplexes requests through Symfony HttpClient.
 *
 * Pass the same Symfony client that backs the PSR-18 adapter so timeouts,
 * proxies, and TLS settings apply to single and batch calls.
 */
final class SymfonyConcurrentClient implements ConcurrentClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function send(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        /** @var array<int|string, SymfonyResponse> $pending */
        $pending = [];
        try {
            foreach ($requests as $key => $request) {
                $pending[$key] = $this->client->request($request->getMethod(), (string) $request->getUri(), [
                    'headers' => $request->getHeaders(),
                    'body' => (string) $request->getBody(),
                ]);
            }

            foreach ($this->client->stream($pending) as $response => $chunk) {
                unset($response);
                if ($chunk->isTimeout()) {
                    throw new TransportException('A concurrent Space-Track request timed out.');
                }
            }
        } catch (SymfonyTransportException $exception) {
            throw new TransportException('A concurrent Space-Track request failed.', 0, $exception);
        }

        $responses = [];
        foreach ($pending as $key => $response) {
            $psr = $this->responses->createResponse($response->getStatusCode());
            foreach ($response->getHeaders(false) as $name => $values) {
                $psr = $psr->withHeader($name, $values);
            }
            $responses[$key] = $psr->withBody($this->streams->createStream($response->getContent(false)));
        }

        return $responses;
    }
}
