<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Storage\FileStore;

/**
 * Filesystem persistence for OAuth artifacts: authorization requests, codes,
 * access tokens and refresh tokens. Sensitive values (codes, tokens) are stored
 * under a SHA-256 hash of the raw value, so the raw secret never touches disk.
 */
final class TokenStore
{
    private FileStore $requests;
    private FileStore $codes;
    private FileStore $access;
    private FileStore $refresh;

    public function __construct(
        FileStore $requests,
        FileStore $codes,
        FileStore $access,
        FileStore $refresh,
    ) {
        $this->requests = $requests;
        $this->codes = $codes;
        $this->access = $access;
        $this->refresh = $refresh;
    }

    public static function create(string $dataDir): self
    {
        $oauth = $dataDir.\DIRECTORY_SEPARATOR.'oauth';

        return new self(
            new FileStore($oauth.\DIRECTORY_SEPARATOR.'auth_requests'),
            new FileStore($oauth.\DIRECTORY_SEPARATOR.'auth_codes'),
            new FileStore($oauth.\DIRECTORY_SEPARATOR.'access_tokens'),
            new FileStore($oauth.\DIRECTORY_SEPARATOR.'refresh_tokens'),
        );
    }

    // ---- Authorization requests (pending /authorize state) ----

    /**
     * @param array<string, mixed> $request
     */
    public function saveAuthRequest(array $request, int $ttl = 600): string
    {
        $id = bin2hex(random_bytes(16));
        $this->requests->put($id, $request, $ttl);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAuthRequest(string $id): ?array
    {
        return $this->requests->get($id);
    }

    public function deleteAuthRequest(string $id): void
    {
        $this->requests->delete($id);
    }

    // ---- Authorization codes (single use) ----

    /**
     * @param array<string, mixed> $meta
     */
    public function saveAuthCode(string $code, array $meta, int $ttl): void
    {
        $this->codes->put(self::hash($code), $meta, $ttl);
    }

    /**
     * Atomically consume (read + delete) an authorization code.
     *
     * @return array<string, mixed>|null
     */
    public function consumeAuthCode(string $code): ?array
    {
        $key = self::hash($code);
        $record = $this->codes->get($key);
        if (null === $record) {
            return null;
        }

        $this->codes->delete($key);

        return $record;
    }

    // ---- Access tokens ----

    /**
     * @param array<string, mixed> $meta
     */
    public function saveAccessToken(string $token, array $meta, int $ttl): void
    {
        $this->access->put(self::hash($token), $meta, $ttl);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAccessToken(string $token): ?array
    {
        return $this->access->get(self::hash($token));
    }

    public function deleteAccessToken(string $token): void
    {
        $this->access->delete(self::hash($token));
    }

    // ---- Refresh tokens (rotated on use) ----

    /**
     * @param array<string, mixed> $meta
     */
    public function saveRefreshToken(string $token, array $meta, int $ttl): void
    {
        $this->refresh->put(self::hash($token), $meta, $ttl);
    }

    /**
     * Atomically consume (read + delete) a refresh token for rotation.
     *
     * @return array<string, mixed>|null
     */
    public function consumeRefreshToken(string $token): ?array
    {
        $key = self::hash($token);
        $record = $this->refresh->get($key);
        if (null === $record) {
            return null;
        }

        $this->refresh->delete($key);

        return $record;
    }

    public function cleanup(): int
    {
        return $this->requests->cleanup()
            + $this->codes->cleanup()
            + $this->access->cleanup()
            + $this->refresh->cleanup();
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
