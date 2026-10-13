<?php

declare(strict_types=1);

namespace SpacedTracker\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 decorator that redacts every message and context value.
 *
 * Wrap the application logger with this before handing it to the client.
 * The client also wraps once, so a second wrap is harmless.
 */
final class RedactingLogger implements LoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly Redactor $redactor = new Redactor(),
    ) {
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $rendered = $this->redactor->redactString((string) $message);
        $cleanContext = $this->redactor->redact($context);
        if (!is_array($cleanContext)) {
            $cleanContext = ['context' => '[redacted]'];
        }

        /** @var array<string, mixed> $cleanContext */
        $this->inner->log($level, $rendered, $cleanContext);
    }
}
