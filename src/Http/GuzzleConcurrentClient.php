<?php

declare(strict_types=1);

namespace SpacedTracker\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Utils;
use SpacedTracker\Exception\TransportException;
use Psr\Http\Message\ResponseInterface;

final class GuzzleConcurrentClient implements ConcurrentClientInterface
{
    public function __construct(private readonly Client $client)
    {
    }

    public function send(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $promises = [];
        foreach ($requests as $key => $request) {
            $promises[$key] = $this->client->sendAsync($request, ['http_errors' => false]);
        }

        try {
            /** @var array<int|string, array{state: string, value?: mixed, reason?: mixed}> $settled */
            $settled = Utils::settle($promises)->wait();
        } catch (TransferException $exception) {
            throw new TransportException('A concurrent Space-Track request failed.', 0, $exception);
        }

        $responses = [];
        foreach ($settled as $key => $result) {
            if ($result['state'] !== 'fulfilled' || !isset($result['value']) || !$result['value'] instanceof ResponseInterface) {
                $reason = $result['reason'] ?? null;
                throw new TransportException(
                    'A concurrent Space-Track request failed.',
                    0,
                    $reason instanceof \Throwable ? $reason : null,
                );
            }
            $responses[$key] = $result['value'];
        }

        return $responses;
    }
}
