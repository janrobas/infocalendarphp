<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\OAuth\AuthorizationServer;
use App\OAuth\ClientStore;
use App\OAuth\OAuthException;
use App\OAuth\TokenStore;
use App\OAuth\TokenValidator;
use App\Storage\FileStore;

final class OAuthTest extends TestCase
{
    private const REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';

    /**
     * @return array{0: AuthorizationServer, 1: ClientStore, 2: TokenStore}
     */
    private function makeServer(): array
    {
        $config = new Config([
            'APP_URL' => 'https://calendar.example.com',
            'OAUTH_ACCESS_TOKEN_LIFETIME' => '3600',
            'OAUTH_REFRESH_TOKEN_LIFETIME' => '2592000',
        ]);

        $clients = new ClientStore(new FileStore($this->dataDir().'/oauth/clients'));
        $tokens = TokenStore::create($this->dataDir());
        $server = new AuthorizationServer($config, $clients, $tokens);

        return [$server, $clients, $tokens];
    }

    public function testDiscoveryContainsRequiredFields(): void
    {
        [$server] = $this->makeServer();

        $d = $server->discovery();

        self::assertSame('https://calendar.example.com', $d['issuer']);
        self::assertSame('https://calendar.example.com/authorize', $d['authorization_endpoint']);
        self::assertSame('https://calendar.example.com/token', $d['token_endpoint']);
        self::assertSame('https://calendar.example.com/register', $d['registration_endpoint']);
        self::assertContains('S256', $d['code_challenge_methods_supported']);
        self::assertContains('authorization_code', $d['grant_types_supported']);
        self::assertTrue($d['authorization_response_iss_parameter_supported']);
    }

    public function testRegisterClientReturnsClientId(): void
    {
        [$server] = $this->makeServer();

        $reg = $server->registerClient(['redirect_uris' => [self::REDIRECT], 'client_name' => 'ChatGPT']);

        self::assertNotEmpty($reg['client_id']);
        self::assertSame([self::REDIRECT], $reg['redirect_uris']);
        self::assertSame('none', $reg['token_endpoint_auth_method']);
    }

    public function testRegisterClientRequiresRedirectUris(): void
    {
        [$server] = $this->makeServer();

        $this->expectException(\RuntimeException::class);
        $server->registerClient(['client_name' => 'NoRedirect']);
    }

    public function testAuthorizeRejectsInvalidRedirectUri(): void
    {
        [$server] = $this->makeServer();

        $client = $server->registerClient(['redirect_uris' => [self::REDIRECT]]);

        $this->expectException(OAuthException::class);
        $server->validateAuthorizeRequest([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://evil.example.com/callback',
            'code_challenge' => self::challenge('x'),
            'code_challenge_method' => 'S256',
            'resource' => 'https://calendar.example.com/mcp',
        ]);
    }

    public function testAuthorizeRejectsMissingPkce(): void
    {
        [$server] = $this->makeServer();

        $client = $server->registerClient(['redirect_uris' => [self::REDIRECT]]);

        $this->expectException(OAuthException::class);
        $server->validateAuthorizeRequest([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'resource' => 'https://calendar.example.com/mcp',
        ]);
    }

    public function testAuthorizeRejectsMissingResource(): void
    {
        [$server] = $this->makeServer();

        $client = $server->registerClient(['redirect_uris' => [self::REDIRECT]]);

        $this->expectException(OAuthException::class);
        $server->validateAuthorizeRequest([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => self::challenge('x'),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function testFullAuthorizationCodeFlow(): void
    {
        [$server, $clients, $tokens] = $this->makeServer();

        [$verifier, $challenge] = self::pkce();
        $client = $server->registerClient(['redirect_uris' => [self::REDIRECT]]);

        $auth = $server->validateAuthorizeRequest([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://calendar.example.com/mcp',
            'scope' => 'calendar',
            'state' => 'state123',
        ]);

        self::assertSame('state123', $auth['state']);

        $code = $server->issueAuthorizationCode($auth, 'jan');

        $result = $server->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ]);

        self::assertArrayHasKey('access_token', $result);
        self::assertArrayHasKey('refresh_token', $result);
        self::assertSame('Bearer', $result['token_type']);
        self::assertSame('calendar', $result['scope']);

        $validator = new TokenValidator($tokens, $server);
        self::assertTrue($validator->validate($result['access_token'])->isAllowed());
    }

    public function testTokenRejectsWrongVerifier(): void
    {
        [$server] = $this->makeServer();

        [, $challenge] = self::pkce();
        $client = $server->registerClient(['redirect_uris' => [self::REDIRECT]]);

        $auth = $server->validateAuthorizeRequest([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://calendar.example.com/mcp',
        ]);

        $code = $server->issueAuthorizationCode($auth, 'jan');

        $this->expectException(OAuthException::class);
        $server->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::b64url(random_bytes(32)),
        ]);
    }

    public function testAuthorizationCodeIsSingleUse(): void
    {
        [$server] = $this->makeServer();

        [$verifier, $challenge] = self::pkce();
        $client = $server->registerClient(['redirect_uris' => [self::REDIRECT]]);

        $auth = $server->validateAuthorizeRequest([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://calendar.example.com/mcp',
        ]);

        $code = $server->issueAuthorizationCode($auth, 'jan');
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ];

        $server->token($params);

        $this->expectException(OAuthException::class);
        $server->token($params);
    }

    public function testTokenRejectsUnknownCode(): void
    {
        [$server] = $this->makeServer();

        $this->expectException(OAuthException::class);
        $server->token([
            'grant_type' => 'authorization_code',
            'code' => 'does-not-exist',
            'client_id' => 'nope',
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::b64url(random_bytes(32)),
        ]);
    }

    public function testRefreshTokenRotation(): void
    {
        [$server] = $this->makeServer();

        $result = $this->issueViaFlow($server);

        $firstRefresh = $result['refresh_token'];
        $refreshed = $server->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $firstRefresh,
            'client_id' => $result['client_id'],
        ]);

        self::assertNotSame($firstRefresh, $refreshed['refresh_token']);
        self::assertArrayHasKey('access_token', $refreshed);

        // The old refresh token is now invalid (rotation).
        $this->expectException(OAuthException::class);
        $server->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $firstRefresh,
            'client_id' => $result['client_id'],
        ]);
    }

    public function testExpiredAccessTokenIsRejected(): void
    {
        [$server, , $tokens] = $this->makeServer();

        $tokens->saveAccessToken('expired-token', [
            'client_id' => 'x',
            'subject' => 'jan',
            'scope' => 'calendar',
            'resource' => 'https://calendar.example.com/mcp',
        ], -1);

        $validator = new TokenValidator($tokens, $server);
        self::assertFalse($validator->validate('expired-token')->isAllowed());
    }

    public function testTokenForWrongResourceIsRejected(): void
    {
        [$server, , $tokens] = $this->makeServer();

        $tokens->saveAccessToken('foreign-token', [
            'client_id' => 'x',
            'subject' => 'jan',
            'scope' => 'calendar',
            'resource' => 'https://evil.example.com/mcp',
        ], 3600);

        $validator = new TokenValidator($tokens, $server);
        self::assertFalse($validator->validate('foreign-token')->isAllowed());
    }

    public function testUnknownTokenIsRejected(): void
    {
        [$server, , $tokens] = $this->makeServer();

        $validator = new TokenValidator($tokens, $server);
        self::assertFalse($validator->validate('nope')->isAllowed());
    }

    /**
     * @return array<string, mixed>
     */
    private function issueViaFlow(AuthorizationServer $server): array
    {
        [$verifier, $challenge] = self::pkce();
        $client = $server->registerClient(['redirect_uris' => [self::REDIRECT]]);

        $auth = $server->validateAuthorizeRequest([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://calendar.example.com/mcp',
        ]);

        $code = $server->issueAuthorizationCode($auth, 'jan');

        $result = $server->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ]);

        $result['client_id'] = $client['client_id'];

        return $result;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function pkce(): array
    {
        $verifier = self::b64url(random_bytes(32));

        return [$verifier, self::challenge($verifier)];
    }

    private static function challenge(string $verifier): string
    {
        return self::b64url(hash('sha256', $verifier, true));
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
