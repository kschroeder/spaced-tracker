<?php

declare(strict_types=1);

namespace SpacedTracker\Cache;

use SpacedTracker\Clock\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache that stores one JSON file per key.
 *
 * A null TTL uses the default of 60 seconds. A TTL of zero or less deletes
 * the key, as PSR-16 requires. Values are JSON, so the cache never
 * unserializes PHP. The directory is created at mode 0700 and each file at
 * 0600.
 */
final class FileCache implements CacheInterface
{
    public const DEFAULT_TTL_SECONDS = 60;

    public function __construct(
        private readonly string $directory,
        private readonly int $defaultTtlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if ($this->directory === '' || str_contains($this->directory, "\0")) {
            throw new InvalidArgumentException('Cache directory is empty.');
        }
        if ($this->defaultTtlSeconds < 1) {
            throw new InvalidArgumentException('The default cache TTL must be at least 1 second.');
        }
        $this->prepareDirectory();
    }

    public function defaultTtlSeconds(): int
    {
        return $this->defaultTtlSeconds;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $fetched = $this->fetch($key);

        return $fetched['hit'] ? $fetched['value'] : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->assertKey($key);
        $seconds = $this->secondsFor($ttl);
        if ($seconds < 1) {
            return $this->delete($key);
        }

        try {
            $payload = json_encode([
                'expires_at' => $this->clock->now()->getTimestamp() + $seconds,
                'value' => $value,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return false;
        }

        $directory = $this->prepareDirectory();
        $path = $directory . DIRECTORY_SEPARATOR . $this->filename($key);
        $temporary = tempnam($directory, 'st');
        if ($temporary === false) {
            return false;
        }

        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                return false;
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                return false;
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        $directory = $this->existingDirectory();
        if ($directory === null) {
            return true;
        }
        $this->unlink($directory . DIRECTORY_SEPARATOR . $this->filename($key));

        return true;
    }

    public function clear(): bool
    {
        $directory = $this->existingDirectory();
        if ($directory === null) {
            return true;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if (preg_match('/^[a-f0-9]{64}\.json$/', $entry) !== 1) {
                continue;
            }
            $this->unlink($directory . DIRECTORY_SEPARATOR . $entry);
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $names = $this->keyList($keys);
        $values = [];
        foreach ($names as $key) {
            $fetched = $this->fetch($key);
            $values[$key] = $fetched['hit'] ? $fetched['value'] : $default;
        }

        return $values;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $pairs = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache keys must be strings.');
            }
            $this->assertKey($key);
            $pairs[$key] = $value;
        }

        $stored = true;
        foreach ($pairs as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $stored = false;
            }
        }

        return $stored;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($this->keyList($keys) as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->fetch($key)['hit'];
    }

    /**
     * @return array{hit: bool, value: mixed}
     */
    private function fetch(string $key): array
    {
        $this->assertKey($key);
        $directory = $this->existingDirectory();
        if ($directory === null) {
            return ['hit' => false, 'value' => null];
        }

        $path = $directory . DIRECTORY_SEPARATOR . $this->filename($key);
        if (!is_file($path)) {
            return ['hit' => false, 'value' => null];
        }

        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? $this->decode($raw) : null;
        if ($decoded === null) {
            $this->unlink($path);

            return ['hit' => false, 'value' => null];
        }
        if ($decoded['expires_at'] <= $this->clock->now()->getTimestamp()) {
            $this->unlink($path);

            return ['hit' => false, 'value' => null];
        }

        return ['hit' => true, 'value' => $decoded['value']];
    }

    /**
     * @return array{expires_at: int, value: mixed}|null
     */
    private function decode(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !array_key_exists('expires_at', $decoded) || !array_key_exists('value', $decoded)) {
            return null;
        }
        $expiresAt = $decoded['expires_at'];
        if (!is_int($expiresAt)) {
            return null;
        }

        return ['expires_at' => $expiresAt, 'value' => $decoded['value']];
    }

    private function secondsFor(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtlSeconds;
        }
        if ($ttl instanceof \DateInterval) {
            if ($ttl->invert === 1) {
                return 0;
            }
            $now = $this->clock->now();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,250}$/', $key) !== 1) {
            throw new InvalidArgumentException(
                'Cache key must be 1 to 250 characters of letters, numbers, dot, underscore, or hyphen.',
            );
        }
    }

    /**
     * @param iterable<mixed> $keys
     * @return list<string>
     */
    private function keyList(iterable $keys): array
    {
        $names = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache keys must be strings.');
            }
            $this->assertKey($key);
            $names[] = $key;
        }

        return $names;
    }

    private function filename(string $key): string
    {
        return hash('sha256', $key) . '.json';
    }

    private function prepareDirectory(): string
    {
        if (is_dir($this->directory)) {
            return $this->directory;
        }
        if (file_exists($this->directory)) {
            throw new CacheException('Cache directory is not a directory.');
        }
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new CacheException('Could not create the cache directory.');
        }
        chmod($this->directory, 0700);

        return $this->directory;
    }

    private function existingDirectory(): ?string
    {
        if (is_dir($this->directory)) {
            return $this->directory;
        }
        if (file_exists($this->directory)) {
            throw new CacheException('Cache directory is not a directory.');
        }

        return null;
    }

    private function unlink(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
