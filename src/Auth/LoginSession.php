<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * A tiny signed-cookie login session for the single local user.
 *
 * The cookie value is `base64(payload).hmac`. The signing key lives in
 * `data/session.key` (auto-generated on first use) so sessions survive across
 * PHP workers without a database or native session store.
 */
final class LoginSession
{
    public const COOKIE = 'cal_auth';

    public function __construct(private readonly string $key)
    {
    }

    public function issue(string $userId, int $ttl = 28800): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub' => $userId,
            'exp' => time() + $ttl,
        ], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.$this->sign($payload);
    }

    public function verify(?string $cookie): ?string
    {
        if (!\is_string($cookie) || '' === $cookie) {
            return null;
        }

        $parts = explode('.', $cookie, 2);
        if (2 !== \count($parts)) {
            return null;
        }

        [$payload, $signature] = $parts;

        if (!hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $data = json_decode(base64_decode(strtr($payload, '-_', '+/'), true) ?: '', true);
        if (!\is_array($data) || !isset($data['sub'], $data['exp'])) {
            return null;
        }

        if ((int) $data['exp'] < time()) {
            return null;
        }

        return (string) $data['sub'];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key);
    }

    public static function loadOrCreateKey(string $dataDir): string
    {
        $path = $dataDir.\DIRECTORY_SEPARATOR.'session.key';

        if (is_file($path)) {
            $key = trim((string) @file_get_contents($path));
            if ('' !== $key) {
                return $key;
            }
        }

        $key = bin2hex(random_bytes(32));
        @file_put_contents($path, $key, \LOCK_EX);
        @chmod($path, 0600);

        return $key;
    }
}
