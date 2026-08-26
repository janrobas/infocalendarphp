<?php

declare(strict_types=1);

namespace App\Auth;

use App\Storage\FileStore;

/**
 * Filesystem-backed login rate limiter. Tracks failed attempts per key
 * (typically the client IP) over a sliding time window.
 */
final class RateLimiter
{
    private const WINDOW = 900;  // 15 minutes
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly FileStore $store)
    {
    }

    public function tooManyAttempts(string $key): bool
    {
        $record = $this->store->get(self::safeKey($key));
        if (null === $record) {
            return false;
        }

        $now = time();
        $attempts = array_values(array_filter(
            \is_array($record['attempts'] ?? null) ? $record['attempts'] : [],
            static fn ($t): bool => \is_int($t) && $t > $now - self::WINDOW,
        ));

        return \count($attempts) >= self::MAX_ATTEMPTS;
    }

    public function recordFailure(string $key): void
    {
        $safe = self::safeKey($key);
        $now = time();

        $record = $this->store->get($safe) ?? ['attempts' => []];
        $attempts = array_values(array_filter(
            \is_array($record['attempts'] ?? null) ? $record['attempts'] : [],
            static fn ($t): bool => \is_int($t) && $t > $now - self::WINDOW,
        ));
        $attempts[] = $now;

        $this->store->put($safe, ['attempts' => $attempts]);
    }

    public function reset(string $key): void
    {
        $this->store->delete(self::safeKey($key));
    }

    private static function safeKey(string $key): string
    {
        return substr(hash('sha256', $key), 0, 32);
    }
}
