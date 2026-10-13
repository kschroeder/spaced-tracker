<?php

declare(strict_types=1);

namespace SpacedTracker\Auth;

use Psr\Http\Message\ResponseInterface;

final class CookieParser
{
    /**
     * @return array{cookies: array<string, string>, expiresAt: \DateTimeImmutable}
     */
    public function parse(ResponseInterface $response, \DateTimeImmutable $now, int $defaultTtlSeconds): array
    {
        $cookies = [];
        $expiries = [];

        foreach ($response->getHeader('Set-Cookie') as $header) {
            $parsed = $this->parseHeader($header, $now);
            if ($parsed === null) {
                continue;
            }
            [$name, $value, $expiresAt] = $parsed;
            if ($value === '') {
                unset($cookies[$name]);
                continue;
            }
            $cookies[$name] = $value;
            if ($expiresAt !== null) {
                $expiries[] = $expiresAt;
            }
        }

        $expiresAt = $expiries === []
            ? $now->modify('+' . $defaultTtlSeconds . ' seconds')
            : $this->earliest($expiries);

        return [
            'cookies' => $cookies,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: \DateTimeImmutable|null}|null
     */
    private function parseHeader(string $header, \DateTimeImmutable $now): ?array
    {
        $parts = array_map('trim', explode(';', $header));
        $pair = array_shift($parts);
        if ($pair === '' || !str_contains($pair, '=')) {
            return null;
        }

        [$name, $value] = explode('=', $pair, 2);
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $expiresAt = null;
        foreach ($parts as $attribute) {
            if (!str_contains($attribute, '=')) {
                continue;
            }
            [$attributeName, $attributeValue] = explode('=', $attribute, 2);
            $attributeName = strtolower(trim($attributeName));
            $attributeValue = trim($attributeValue);
            if ($attributeName === 'max-age' && is_numeric($attributeValue)) {
                $seconds = (int) $attributeValue;
                $expiresAt = $seconds <= 0 ? $now : $now->modify('+' . $seconds . ' seconds');
            }
            if ($attributeName === 'expires' && $expiresAt === null) {
                $parsed = date_create_immutable($attributeValue);
                if ($parsed instanceof \DateTimeImmutable) {
                    $expiresAt = $parsed;
                }
            }
        }

        return [$name, $value, $expiresAt];
    }

    /**
     * @param list<\DateTimeImmutable> $expiries
     */
    private function earliest(array $expiries): \DateTimeImmutable
    {
        $earliest = $expiries[0];
        foreach ($expiries as $expiry) {
            if ($expiry < $earliest) {
                $earliest = $expiry;
            }
        }

        return $earliest;
    }
}
