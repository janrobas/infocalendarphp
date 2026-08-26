<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\LoginSession;
use App\Auth\RateLimiter;
use App\Config;
use App\Http\OAuthController;
use App\OAuth\AuthorizationServer;
use App\OAuth\ClientStore;
use App\OAuth\TokenStore;
use App\Storage\FileStore;
use Http\Discovery\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

final class OAuthControllerTest extends TestCase
{
    /**
     * @return array{0: OAuthController, 1: TokenStore, 2: Config, 3: AuthorizationServer, 4: LoginSession}
     */
    private function makeController(): array
    {
        $config = new Config([
            'APP_URL' => 'https://calendar.example.com',
            'OAUTH_USERNAME' => 'jan',
            'OAUTH_PASSWORD_HASH' => password_hash('secret123', \PASSWORD_DEFAULT),
        ]);

        $clients = new ClientStore(new FileStore($this->dataDir().'/oauth/clients'));
        $tokens = TokenStore::create($this->dataDir());
        $server = new AuthorizationServer($config, $clients, $tokens);
        $sessions = new LoginSession('test-signing-key');
        $rateLimiter = new RateLimiter(new FileStore($this->dataDir().'/rate_limit'));

        return [new OAuthController($server, $clients, $tokens, $config, $sessions, $rateLimiter), $tokens, $config, $server, $sessions];
    }

    public function testLoginWithValidCredentialsRedirectsToConsent(): void
    {
        [$controller, $tokens] = $this->makeController();

        $id = $tokens->saveAuthRequest(['client_id' => 'c', 'redirect_uri' => 'https://x/', 'scope' => 'calendar', 'state' => '', 'code_challenge' => 'ch', 'resource' => 'https://calendar.example.com/mcp', 'client_name' => 'ChatGPT']);

        $response = $controller->login($this->request('/login', ['id' => $id, 'username' => 'jan', 'password' => 'secret123']));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/consent?id='.$id, $response->getHeaderLine('Location'));
        self::assertNotSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testLoginWithInvalidCredentialsShowsError(): void
    {
        [$controller, $tokens] = $this->makeController();

        $id = $tokens->saveAuthRequest(['client_id' => 'c', 'redirect_uri' => 'https://x/', 'scope' => 'calendar', 'state' => '', 'code_challenge' => 'ch', 'resource' => 'https://calendar.example.com/mcp', 'client_name' => 'ChatGPT']);

        $response = $controller->login($this->request('/login', ['id' => $id, 'username' => 'jan', 'password' => 'wrong']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Invalid username or password', (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testAuthorizeRendersLoginWhenNotAuthenticated(): void
    {
        [$controller, , , $server] = $this->makeController();

        $client = $server->registerClient(['redirect_uris' => ['https://chatgpt.com/callback']]);
        [, $challenge] = $this->pkce();

        $request = (new Psr17Factory())->createServerRequest('GET', 'https://calendar.example.com/authorize')
            ->withQueryParams([
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://chatgpt.com/callback',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'resource' => 'https://calendar.example.com/mcp',
                'scope' => 'calendar',
                'state' => 's',
            ]);

        $response = $controller->authorize($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<form', (string) $response->getBody());
    }

    public function testAuthorizeWithInvalidRedirectShowsError(): void
    {
        [$controller, , , $server] = $this->makeController();

        $client = $server->registerClient(['redirect_uris' => ['https://chatgpt.com/callback']]);

        $request = (new Psr17Factory())->createServerRequest('GET', 'https://calendar.example.com/authorize')
            ->withQueryParams([
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://evil.example.com/',
                'code_challenge' => 'challenge',
                'code_challenge_method' => 'S256',
                'resource' => 'https://calendar.example.com/mcp',
            ]);

        $response = $controller->authorize($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testConsentApprovalRedirectsWithCode(): void
    {
        [$controller, $tokens, , , $sessions] = $this->makeController();

        $id = $tokens->saveAuthRequest([
            'client_id' => 'c',
            'redirect_uri' => 'https://chatgpt.com/callback',
            'scope' => 'calendar',
            'state' => 'xyz',
            'code_challenge' => 'challenge',
            'code_challenge_method' => 'S256',
            'resource' => 'https://calendar.example.com/mcp',
            'client_name' => 'ChatGPT',
        ]);

        $cookie = $sessions->issue('jan');

        $request = (new Psr17Factory())->createServerRequest('POST', 'https://calendar.example.com/consent', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody(['id' => $id, 'action' => 'approve'])
            ->withCookieParams([LoginSession::COOKIE => $cookie]);

        $response = $controller->consent($request);

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('https://chatgpt.com/callback?', $location);
        self::assertStringContainsString('code=', $location);
        self::assertStringContainsString('state=xyz', $location);
        self::assertStringContainsString('iss=https%3A%2F%2Fcalendar.example.com', $location);
    }

    public function testConsentWithoutLoginShowsLoginForm(): void
    {
        [$controller, $tokens] = $this->makeController();

        $id = $tokens->saveAuthRequest([
            'client_id' => 'c',
            'redirect_uri' => 'https://chatgpt.com/callback',
            'scope' => 'calendar',
            'state' => '',
            'code_challenge' => 'challenge',
            'resource' => 'https://calendar.example.com/mcp',
            'client_name' => 'ChatGPT',
        ]);

        $request = (new Psr17Factory())->createServerRequest('POST', 'https://calendar.example.com/consent', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody(['id' => $id, 'action' => 'approve']);

        $response = $controller->consent($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<form', (string) $response->getBody());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $uri, array $body): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', $uri, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody($body);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }
}
