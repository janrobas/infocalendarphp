<?php

declare(strict_types=1);

namespace App\OAuth;

use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * Resource-server token validator: validates opaque access tokens issued by
 * {@see AuthorizationServer} against the filesystem token store.
 */
final class TokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly AuthorizationServer $server,
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
        if ('' === $resource || !$this->server->resourceMatches($resource)) {
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
}
