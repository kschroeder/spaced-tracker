<?php

declare(strict_types=1);

namespace SpacedTracker\Log;

/**
 * Removes credentials, cookies, and bearer tokens from values before they
 * reach a log record. Redaction is structural (array keys) and textual.
 */
final class Redactor
{
    private const REPLACEMENT = '[redacted]';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'identity',
        'api_key',
        'apikey',
        'api-key',
        'x-api-key',
        'credentials',
        'php-auth-pw',
        'php-auth-user',
    ];

    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->keyIsSensitive($key)) {
                    $redacted[$key] = self::REPLACEMENT;
                    continue;
                }
                $redacted[$key] = $this->redact($item);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (is_object($value)) {
            return self::REPLACEMENT;
        }

        return $value;
    }

    public function redactString(string $value): string
    {
        $patterns = [
            '/((?:password|passwd|identity|secret|api_key|apikey|token)=)([^&\s]+)/i',
            '/(Bearer\s+)[A-Za-z0-9\-._~+\/=]+/i',
            '/(chocolatechip=)[^;\s]+/i',
            '/((?:Cookie|Set-Cookie|Authorization):\s*)([^\r\n]+)/i',
        ];
        $replacements = [
            '$1' . self::REPLACEMENT,
            '$1' . self::REPLACEMENT,
            '$1' . self::REPLACEMENT,
            '$1' . self::REPLACEMENT,
        ];

        $redacted = preg_replace($patterns, $replacements, $value);

        return $redacted ?? $value;
    }

    private function keyIsSensitive(string $key): bool
    {
        $normalized = strtolower(str_replace([' ', '_'], ['-', '-'], $key));
        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
