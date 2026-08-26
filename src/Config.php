<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Reads application configuration from environment variables (typically a `.env` file).
 */
final class Config
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /**
     * Load configuration from a `.env` file in $baseDir (if present) plus the
     * real process environment. Explicitly-passed environment values win over `.env`.
     */
    public static function load(string $baseDir): self
    {
        $envFile = rtrim($baseDir, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.'.env';

        if (is_file($envFile)) {
            Dotenv::createImmutable($baseDir)->safeLoad();
        }

        $env = [];
        if (\is_array($raw = getenv())) {
            $env = $raw;
        }

        return new self(array_merge($env, $_SERVER, $_ENV));
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->values)) {
            $value = $this->values[$key];

            return \is_string($value) && '' !== $value ? $value : $default;
        }

        return $default;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if (null === $value) {
            throw new RuntimeException(sprintf('Missing required configuration "%s".', $key));
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        if (null === $value) {
            return $default;
        }

        return (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if (null === $value) {
            return $default;
        }

        return \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function appUrl(): string
    {
        return rtrim($this->require('APP_URL'), '/');
    }

    public function mcpUrl(): string
    {
        return $this->appUrl().'/mcp';
    }

    public function dataDir(string $baseDir): string
    {
        return rtrim($baseDir, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.'data';
    }

    public function isDebug(): bool
    {
        return $this->bool('APP_DEBUG', false);
    }
}
