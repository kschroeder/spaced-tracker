<?php

declare(strict_types=1);

namespace SpacedTracker\Http;

use SpacedTracker\Exception\TransportException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Issues many PSR-7 requests with curl_multi.
 *
 * Opt in to this client. A raw curl handle does not inherit middleware,
 * proxies, or TLS settings configured only on another PSR-18 client.
 */
final class CurlMultiConcurrentClient implements ConcurrentClientInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly float $timeoutSeconds = 60.0,
        private readonly bool $verifyTls = true,
    ) {
        if (!function_exists('curl_multi_init')) {
            throw new TransportException('ext-curl is required for the curl_multi concurrent client.');
        }
    }

    public function send(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $multi = curl_multi_init();

        $handles = [];
        /** @var \ArrayObject<int|string, list<string>> $headerBuckets */
        $headerBuckets = new \ArrayObject();
        foreach ($requests as $key => $request) {
            $handle = $this->handle($request, $headerBuckets, $key);
            $handles[$key] = $handle;
            curl_multi_add_handle($multi, $handle);
        }

        try {
            $running = 0;
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            if ($status !== CURLM_OK) {
                throw new TransportException('curl_multi failed while sending Space-Track requests.');
            }

            $failed = [];
            while (($info = curl_multi_info_read($multi)) !== false) {
                $result = (int) ($info['result'] ?? CURLE_OK);
                if ($result !== CURLE_OK && isset($info['handle']) && $info['handle'] instanceof \CurlHandle) {
                    $failed[spl_object_id($info['handle'])] = curl_strerror($result);
                }
            }

            $responses = [];
            foreach ($handles as $key => $handle) {
                $error = $failed[spl_object_id($handle)] ?? curl_error($handle);
                if ($error !== '') {
                    throw new TransportException('A concurrent Space-Track request failed: ' . $error);
                }
                $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $body = curl_multi_getcontent($handle);
                /** @var list<string> $rawHeaders */
                $rawHeaders = $headerBuckets[$key] ?? [];
                $responses[$key] = $this->toResponse($statusCode, is_string($body) ? $body : '', $rawHeaders);
            }

            return $responses;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
        }
    }

    /**
     * @param \ArrayObject<int|string, list<string>> $headerBuckets
     */
    private function handle(RequestInterface $request, \ArrayObject $headerBuckets, int|string $key): \CurlHandle
    {
        $headerBuckets[$key] = [];
        $uri = (string) $request->getUri();
        $method = $request->getMethod();
        if ($uri === '' || $method === '') {
            throw new TransportException('A Space-Track request needs a method and a URL.');
        }
        $handle = curl_init();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headerName = (string) $name;
            if ($headerName === '') {
                continue;
            }
            foreach ($values as $value) {
                $headers[] = $headerName . ': ' . $value;
            }
        }

        $configured = curl_setopt_array($handle, [
            CURLOPT_URL => $uri,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => (int) ceil($this->timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use ($headerBuckets, $key): int {
                unset($curl);
                $bucket = $headerBuckets[$key];
                $bucket[] = $header;
                $headerBuckets[$key] = $bucket;

                return strlen($header);
            },
        ]);
        if ($configured === false) {
            throw new TransportException('Unable to configure a curl handle.');
        }

        $body = (string) $request->getBody();
        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        return $handle;
    }

    /**
     * @param list<string> $rawHeaders
     */
    private function toResponse(int $status, string $body, array $rawHeaders): ResponseInterface
    {
        $response = $this->responses->createResponse($status > 0 ? $status : 502);
        foreach ($this->parseHeaders($rawHeaders) as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody($this->streams->createStream($body));
    }

    /**
     * @param list<string> $rawHeaders
     * @return array<string, list<string>>
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $parsed = [];
        foreach ($rawHeaders as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $parsed[trim($name)][] = trim($value);
        }

        return $parsed;
    }
}
