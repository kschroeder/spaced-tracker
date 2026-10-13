<?php

declare(strict_types=1);

namespace SpacedTracker\Http;

use SpacedTracker\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

final class SequentialConcurrentClient implements ConcurrentClientInterface
{
    public function __construct(private readonly ClientInterface $http)
    {
    }

    public function send(array $requests): array
    {
        $responses = [];
        foreach ($requests as $key => $request) {
            try {
                $responses[$key] = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $exception) {
                $recovered = $this->responseFrom($exception);
                if (!$recovered instanceof ResponseInterface) {
                    throw new TransportException('A Space-Track request in the batch failed.', 0, $exception);
                }
                $responses[$key] = $recovered;
            }
        }

        return $responses;
    }

    private function responseFrom(ClientExceptionInterface $exception): ?ResponseInterface
    {
        if (!method_exists($exception, 'getResponse')) {
            return null;
        }

        $response = $exception->getResponse();

        return $response instanceof ResponseInterface ? $response : null;
    }
}
