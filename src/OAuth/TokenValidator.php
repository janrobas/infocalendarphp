<?php

declare(strict_types=1);

namespace App\OAuth;

use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * Resource-server token validator: validates opaque access tokens issued by
 * {@see AuthorizationServer} against the filesystem token store.
 *
 * When the server exposes more than one MCP endpoint (for example the default
 * endpoint and a full-mailbox one), each endpoint gets its own validator with
 * its own resource identifiers. That keeps tokens audience-bound: a token
 * issued for `/mcp` cannot be replayed on `/mcp/full` or vice versa.
 */
final class TokenValidator implements AuthorizationTokenValidatorInterface
{
    /**
     * @param list<string> $allowedResources resource identifiers accepted by this
     *                                       endpoint; empty means the server defaults
     */
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly AuthorizationServer $server,
        private readonly array $allowedResources = [],
    ) {
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        if ('' === $accessToken) {
            return AuthorizationResult::unauthorized('invalid_token', 'Missing access token.');
        }

        $record = $this->tokens->getAccessToken($accessToken);
        if (null === $record) {
            return AuthorizationResult::unauthorized('invalid_token', 'The access token is invalid or has expired.');
        }

        $resource = \is_string($record['resource'] ?? null) ? (string) $record['resource'] : '';
        if ('' === $resource || !$this->allowsResource($resource)) {
            return AuthorizationResult::unauthorized('invalid_token', 'The access token was not issued for this resource.');
        }

        $scope = \is_string($record['scope'] ?? null) ? (string) $record['scope'] : '';
        $scopes = [] === explode(' ', $scope) ? [] : array_values(array_filter(explode(' ', $scope)));

        return AuthorizationResult::allow([
            'oauth.subject' => (string) ($record['subject'] ?? ''),
            'oauth.client_id' => (string) ($record['client_id'] ?? ''),
            'oauth.scopes' => $scopes,
        ]);
    }

    private function allowsResource(string $resource): bool
    {
        $expected = [] === $this->allowedResources
            ? $this->server->allowedResources()
            : $this->allowedResources;

        $resource = ResourceIdentifier::canonicalize($resource);
        if (null === $resource) {
            return false;
        }

        foreach ($expected as $candidate) {
            if ($resource === ResourceIdentifier::canonicalize($candidate)) {
                return true;
            }
        }

        return false;
    }
}
