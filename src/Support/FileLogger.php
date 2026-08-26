<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * A small PSR-3 logger that appends to a file. It never logs sensitive values:
 * callers are responsible for not passing credentials/tokens as context.
 */
final class FileLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 100,
        LogLevel::INFO => 200,
        LogLevel::NOTICE => 250,
        LogLevel::WARNING => 300,
        LogLevel::ERROR => 400,
        LogLevel::CRITICAL => 500,
        LogLevel::ALERT => 550,
        LogLevel::EMERGENCY => 600,
    ];

    public function __construct(
        private readonly string $path,
        private readonly int $minimumLevel = 200,
        private readonly ?SecretRedactor $redactor = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (($this::LEVELS[$level] ?? 0) < $this->minimumLevel) {
            return;
        }

        $message = (string) $message;
        $contextJson = '' === $context ? '' : (string) json_encode($context, \JSON_UNESCAPED_SLASHES);

        if (null !== $this->redactor) {
            $message = $this->redactor->redact($message);
            $contextJson = $this->redactor->redact($contextJson);
        }

        $line = sprintf(
            '[%s] %s: %s%s%s',
            gmdate('c'),
            strtoupper($level),
            $message,
            '' === $contextJson ? '' : ' ',
            $contextJson,
        ).\PHP_EOL;

        @file_put_contents($this->path, $line, \FILE_APPEND | \LOCK_EX);
    }
}
