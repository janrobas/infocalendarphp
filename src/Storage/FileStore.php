<?php

declare(strict_types=1);

namespace App\Storage;

use JsonException;
use RuntimeException;

/**
 * Minimal JSON-on-disk store with atomic writes and expiry support.
 *
 * Each record is a JSON file inside a dedicated directory. Keys are restricted
 * to a safe character set so a key can never traverse the filesystem.
 */
final class FileStore
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create storage directory "%s".', $this->directory));
        }
    }

    /**
     * Atomically write a record. When $expiresIn is provided the record expires
     * that many seconds from now.
     *
     * @param array<string, mixed> $data
     */
    public function put(string $key, array $data, ?int $expiresIn = null): void
    {
        $this->assertKey($key);

        if (null !== $expiresIn) {
            $data['expires_at'] = time() + $expiresIn;
        }

        $json = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $tmp = @tempnam($this->directory, $key.'.tmp.');
        if (false === $tmp) {
            throw new RuntimeException(sprintf('Unable to create temporary file in "%s".', $this->directory));
        }

        try {
            if (false === @file_put_contents($tmp, $json, \LOCK_EX)) {
                throw new RuntimeException(sprintf('Unable to write storage file "%s".', $key));
            }

            @chmod($tmp, 0640);

            if (!@rename($tmp, $this->pathFor($key))) {
                throw new RuntimeException(sprintf('Unable to atomically move storage file "%s".', $key));
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Read a record. Returns null when missing or expired (expired records are removed).
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $this->assertKey($key);

        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if (false === $contents) {
            return null;
        }

        try {
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        if (isset($data['expires_at']) && \is_int($data['expires_at']) && $data['expires_at'] <= time()) {
            $this->delete($key);

            return null;
        }

        unset($data['expires_at']);

        return $data;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);

        return is_file($this->pathFor($key));
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);

        $path = $this->pathFor($key);
        if (is_file($path)) {
            return @unlink($path);
        }

        return true;
    }

    /**
     * Remove all expired records. Returns the number removed.
     */
    public function cleanup(): int
    {
        $removed = 0;
        $now = time();

        foreach ($this->entries() as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->directory.\DIRECTORY_SEPARATOR.$entry;
            if (!is_file($path)) {
                continue;
            }

            $contents = @file_get_contents($path);
            if (false === $contents) {
                continue;
            }

            try {
                $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (\is_array($data) && isset($data['expires_at']) && \is_int($data['expires_at']) && $data['expires_at'] <= $now) {
                @unlink($path);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->entries() as $entry) {
            if ($entry === '.' || $entry === '..' || !is_file($this->directory.\DIRECTORY_SEPARATOR.$entry)) {
                continue;
            }
            $keys[] = $entry;
        }

        return $keys;
    }

    private function pathFor(string $key): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$key;
    }

    /**
     * @return list<string>
     */
    private function entries(): array
    {
        $entries = @scandir($this->directory);
        if (false === $entries) {
            return [];
        }

        return $entries;
    }

    private function assertKey(string $key): void
    {
        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new RuntimeException('Invalid storage key.');
        }
    }
}
