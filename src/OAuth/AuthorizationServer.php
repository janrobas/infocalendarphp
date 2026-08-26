<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Config;

/**
 * Standards-compliant OAuth 2.1 authorization server (authorization code + PKCE,
 * RFC 8414 metadata, RFC 7591 dynamic client registration, RFC 8707 resource
 * indicators, RFC 9207 issuer identification).
 *
 * This is a single-resource server: it only issues tokens for its own MCP
 * endpoint. Tokens are opaque random strings persisted via {@see TokenStore}.
 */
final class AuthorizationServer
{
    public const SCOPE = 'calendar';

    public function __construct(
        private readonly Config $config,
        private readonly ClientStore $clients,
        private readonly TokenStore $tokens,
    ) {
    }

    /**
     * RFC 8414 authorization server metadata.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $base = $this->config->appUrl();

        return [
            'issuer' => $base,
            'authorization_endpoint' => $base.'/authorize',
            'token_endpoint' => $base.'/token',
            'registration_endpoint' => $base.'/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => $base,
        ];
    }

    /**
     * RFC 7591 dynamic client registration.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function registerClient(array $body): array
    {
        $registration = $this->clients->register($body);

        return [
            'client_id' => $registration['client_id'],
            'client_id_issued_at' => $registration['client_id_issued_at'],
            'client_name' => $registration['client_name'],
            'redirect_uris' => $registration['redirect_uris'],
            'grant_types' => $registration['grant_types'],
            'response_types' => $registration['response_types'],
            'token_endpoint_auth_method' => $registration['token_endpoint_auth_method'],
        ];
    }

    /**
     * Validate an authorization request and return its normalized parameters.
     *
     * @param array<string, mixed> $params
     *
     * @return array{client_id: string, redirect_uri: string, scope: string, state: string, code_challenge: string, resource: string}
     *
     * @throws OAuthException
     */
    public function validateAuthorizeRequest(array $params): array
    {
        $responseType = $params['response_type'] ?? null;
        if ('code' !== $responseType) {
            throw OAuthException::invalidRequest('response_type must be "code".');
        }

        $clientId = $params['client_id'] ?? null;
        if (!\is_string($clientId) || '' === $clientId) {
            throw OAuthException::invalidRequest('client_id is required.');
        }

        $client = $this->clients->get($clientId);
        if (null === $client) {
            throw new OAuthException('unauthorized_client', 'The client is not registered.', 401);
        }

        $redirectUri = $params['redirect_uri'] ?? null;
        if (!\is_string($redirectUri) || '' === $redirectUri) {
            throw OAuthException::invalidRequest('redirect_uri is required.');
        }
        if (!$this->clients->isRedirectUriAllowed($clientId, $redirectUri)) {
            throw new OAuthException('invalid_request', 'The redirect_uri is not registered for this client.', 400);
        }

        $challenge = $params['code_challenge'] ?? null;
        if (!\is_string($challenge) || '' === $challenge) {
            throw OAuthException::invalidRequest('code_challenge is required (PKCE).');
        }

        $method = $params['code_challenge_method'] ?? 'S256';
        if ('S256' !== $method) {
            throw OAuthException::invalidRequest('Unsupported code_challenge_method; only S256 is supported.');
        }

        $resource = $this->normalizeResource($params['resource'] ?? null);
        if (null === $resource) {
            throw OAuthException::invalidRequest('resource is required (RFC 8707).');
        }

        $scope = \is_string($params['scope'] ?? null) && '' !== ($params['scope'] ?? '') ? (string) $params['scope'] : self::SCOPE;
        if (!$this->isValidScope($scope)) {
            throw OAuthException::invalidScope();
        }

        $state = \is_string($params['state'] ?? null) ? (string) $params['state'] : '';

        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => $method,
            'resource' => $resource,
        ];
    }

    /**
     * Issue an authorization code for an approved request.
     */
    public function issueAuthorizationCode(array $request, string $userId): string
    {
        $code = self::randomToken();

        $this->tokens->saveAuthCode($code, [
            'client_id' => $request['client_id'],
            'redirect_uri' => $request['redirect_uri'],
            'scope' => $request['scope'],
            'resource' => $request['resource'],
            'code_challenge' => $request['code_challenge'],
            'subject' => $userId,
            'created_at' => time(),
        ], 300);

        return $code;
    }

    /**
     * Token endpoint.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     *
     * @throws OAuthException
     */
    public function token(array $body): array
    {
        $grantType = $body['grant_type'] ?? null;

        return match ($grantType) {
            'authorization_code' => $this->exchangeAuthorizationCode($body),
            'refresh_token' => $this->refreshToken($body),
            default => throw OAuthException::invalidGrant('Unsupported grant_type.'),
        };
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function exchangeAuthorizationCode(array $body): array
    {
        $code = $body['code'] ?? null;
        if (!\is_string($code) || '' === $code) {
            throw OAuthException::invalidRequest('code is required.');
        }

        $record = $this->tokens->consumeAuthCode($code);
        if (null === $record) {
            throw OAuthException::invalidGrant('The authorization code is invalid or has expired.');
        }

        $clientId = $body['client_id'] ?? null;
        if (!\is_string($clientId) || $clientId !== $record['client_id']) {
            throw OAuthException::invalidClient();
        }

        $redirectUri = $body['redirect_uri'] ?? null;
        if (!\is_string($redirectUri) || $redirectUri !== $record['redirect_uri']) {
            throw OAuthException::invalidGrant('redirect_uri does not match the authorization request.');
        }

        $verifier = $body['code_verifier'] ?? null;
        if (!\is_string($verifier) || !$this->verifyPkce($verifier, $record['code_challenge'])) {
            throw OAuthException::invalidGrant('PKCE verification failed.');
        }

        return $this->issueTokens(
            $record['client_id'],
            $record['subject'],
            $record['scope'],
            $record['resource'],
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function refreshToken(array $body): array
    {
        $token = $body['refresh_token'] ?? null;
        if (!\is_string($token) || '' === $token) {
            throw OAuthException::invalidRequest('refresh_token is required.');
        }

        $record = $this->tokens->consumeRefreshToken($token);
        if (null === $record) {
            throw OAuthException::invalidGrant('The refresh token is invalid or has expired.');
        }

        $clientId = $body['client_id'] ?? null;
        if (!\is_string($clientId) || $clientId !== $record['client_id']) {
            throw OAuthException::invalidClient();
        }

        return $this->issueTokens(
            $record['client_id'],
            $record['subject'],
            $record['scope'],
            $record['resource'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function issueTokens(string $clientId, string $subject, string $scope, string $resource): array
    {
        $accessToken = self::randomToken();
        $refreshToken = self::randomToken();

        $accessTtl = $this->config->int('OAUTH_ACCESS_TOKEN_LIFETIME', 3600);
        $refreshTtl = $this->config->int('OAUTH_REFRESH_TOKEN_LIFETIME', 2592000);

        $meta = [
            'client_id' => $clientId,
            'subject' => $subject,
            'scope' => $scope,
            'resource' => $resource,
            'created_at' => time(),
        ];

        $this->tokens->saveAccessToken($accessToken, $meta, $accessTtl);
        $this->tokens->saveRefreshToken($refreshToken, $meta, $refreshTtl);

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_token' => $refreshToken,
            'scope' => $scope,
            'resource' => $resource,
        ];
    }

    /**
     * Whether the given resource identifier matches this server's own MCP endpoint.
     */
    public function resourceMatches(string $normalizedResource): bool
    {
        return \in_array($normalizedResource, $this->allowedResources(), true);
    }

    /**
     * @return list<string>
     */
    public function allowedResources(): array
    {
        $base = $this->normalizeResource($this->config->appUrl());
        $mcp = $this->normalizeResource($this->config->mcpUrl());

        return [$base, $mcp];
    }

    private function isValidScope(string $scope): bool
    {
        foreach (explode(' ', $scope) as $s) {
            if ('' !== $s && self::SCOPE !== $s) {
                return false;
            }
        }

        return true;
    }

    private function verifyPkce(string $verifier, string $challenge): bool
    {
        return hash_equals($challenge, self::base64UrlEncode(hash('sha256', $verifier, true)));
    }

    /**
     * Normalize a resource URI for comparison, or null when malformed.
     */
    private function normalizeResource(mixed $resource): ?string
    {
        if (!\is_string($resource) || '' === $resource) {
            return null;
        }

        $parts = parse_url($resource);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.$host.$port.$path;
    }

    private static function randomToken(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
