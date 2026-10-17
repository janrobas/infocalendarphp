<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Filesystem-backed sliding-window counter.
 *
 * Records timestamped events per key (a client IP for login attempts, a fixed
 * label for outgoing mail) and reports whether the configured maximum has been
 * reached within the window. The key may be arbitrary text: it is hashed before
 * it is used as a storage key.
 */
final class RateLimiter
{
    public function __construct(
        private readonly FileStore $store,
        private readonly int $window = 180,
        private readonly int $maxAttempts = 5,
    ) {
    }

    public function tooManyAttempts(string $key): bool
    {
        return \count($this->recent($key)) >= $this->maxAttempts;
    }

    /**
     * Record a single event for the key.
     */
    public function record(string $key): void
    {
        $attempts = $this->recent($key);
        $attempts[] = time();

        $this->store->put(self::safeKey($key), ['attempts' => $attempts]);
    }

    public function reset(string $key): void
    {
        $this->store->delete(self::safeKey($key));
    }

    public function window(): int
    {
        return $this->window;
    }

    /**
     * @return list<int>
     */
    private function recent(string $key): array
    {
        $record = $this->store->get(self::safeKey($key));
        $cutoff = time() - $this->window;

        return array_values(array_filter(
            \is_array($record['attempts'] ?? null) ? $record['attempts'] : [],
            static fn ($t): bool => \is_int($t) && $t > $cutoff,
        ));
    }

    private static function safeKey(string $key): string
    {
        return substr(hash('sha256', $key), 0, 32);
    }
}
