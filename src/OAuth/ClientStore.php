<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Storage\FileStore;
use RuntimeException;

/**
 * Stores OAuth 2.0 clients registered via Dynamic Client Registration (RFC 7591).
 */
final class ClientStore
{
    public function __construct(private readonly FileStore $store)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function register(array $metadata): array
    {
        $redirectUris = $this->normalizeRedirectUris($metadata['redirect_uris'] ?? null);

        $clientId = 'mcp_'.bin2hex(random_bytes(16));
        $createdAt = time();

        $record = [
            'client_id' => $clientId,
            'client_id_issued_at' => $createdAt,
            'client_name' => isset($metadata['client_name']) && \is_string($metadata['client_name'])
                ? substr($metadata['client_name'], 0, 255)
                : '',
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'created_at' => $createdAt,
        ];

        $this->store->put($clientId, $record);

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $clientId): ?array
    {
        return $this->store->get($clientId);
    }

    /**
     * @return list<string>
     */
    public function redirectUris(string $clientId): array
    {
        $client = $this->get($clientId);

        return $client['redirect_uris'] ?? [];
    }

    public function isRedirectUriAllowed(string $clientId, string $redirectUri): bool
    {
        return \in_array($redirectUri, $this->redirectUris($clientId), true);
    }

    /**
     * @param mixed $redirectUris
     *
     * @return list<string>
     */
    private function normalizeRedirectUris(mixed $redirectUris): array
    {
        if (!\is_array($redirectUris) || [] === $redirectUris) {
            throw new RuntimeException('redirect_uris is required.');
        }

        $normalized = [];
        foreach ($redirectUris as $uri) {
            if (!\is_string($uri) || '' === trim($uri)) {
                throw new RuntimeException('redirect_uris must contain valid URI strings.');
            }
            $normalized[] = trim($uri);
        }

        return array_values(array_unique($normalized));
    }
}
