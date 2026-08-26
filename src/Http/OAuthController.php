<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\LoginSession;
use App\Auth\RateLimiter;
use App\Config;
use App\OAuth\AuthorizationServer;
use App\OAuth\ClientStore;
use App\OAuth\OAuthException;
use App\OAuth\TokenStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * HTTP handlers for the OAuth authorization server: /authorize, /login,
 * /consent, /token and /register.
 */
final class OAuthController
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ClientStore $clients,
        private readonly TokenStore $tokens,
        private readonly Config $config,
        private readonly LoginSession $sessions,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        try {
            $auth = $this->server->validateAuthorizeRequest($params);
        } catch (OAuthException $e) {
            return $this->handleAuthorizeError($params, $e);
        }

        $client = $this->clients->get($auth['client_id']);
        $auth['client_name'] = \is_string($client['client_name'] ?? null) && '' !== $client['client_name']
            ? (string) $client['client_name']
            : 'ChatGPT';

        $id = $this->tokens->saveAuthRequest($auth);

        $user = $this->sessions->verify($request->getCookieParams()[LoginSession::COOKIE] ?? null);
        if (null !== $user && hash_equals($this->config->require('OAUTH_USERNAME'), $user)) {
            return Response::redirect('/consent?id='.$id);
        }

        return Response::html(Templates::login($id));
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $id = \is_array($body) && \is_string($body['id'] ?? null) ? $body['id'] : '';

        $ip = $this->clientIp($request);

        if ($this->rateLimiter->tooManyAttempts($ip)) {
            return Response::html(Templates::login($id, 'Too many failed attempts. Please wait and try again.'));
        }

        $username = \is_array($body) && \is_string($body['username'] ?? null) ? (string) $body['username'] : '';
        $password = \is_array($body) && \is_string($body['password'] ?? null) ? (string) $body['password'] : '';

        $expectedUser = $this->config->require('OAUTH_USERNAME');
        $hash = $this->config->require('OAUTH_PASSWORD_HASH');

        $valid = hash_equals($expectedUser, $username) && password_verify($password, $hash);

        if (!$valid) {
            $this->rateLimiter->recordFailure($ip);

            return Response::html(Templates::login($id, 'Invalid username or password.'));
        }

        $this->rateLimiter->reset($ip);

        if (!$this->tokens->getAuthRequest($id)) {
            return Response::html(Templates::error('The authorization request has expired. Please try again.'));
        }

        $cookie = $this->sessions->issue($expectedUser);

        return Response::redirect('/consent?id='.$id)
            ->withHeader('Set-Cookie', $this->cookieHeader($cookie));
    }

    public function consent(ServerRequestInterface $request): ResponseInterface
    {
        if ('POST' === $request->getMethod()) {
            $body = $request->getParsedBody();
            $id = \is_array($body) && \is_string($body['id'] ?? null) ? (string) $body['id'] : '';
        } else {
            $id = (string) ($request->getQueryParams()['id'] ?? '');
        }

        $user = $this->sessions->verify($request->getCookieParams()[LoginSession::COOKIE] ?? null);
        if (null === $user || !hash_equals($this->config->require('OAUTH_USERNAME'), $user)) {
            return Response::html(Templates::login($id));
        }

        if ('POST' === $request->getMethod()) {
            return $this->handleConsentPost($request, $id);
        }

        $auth = $this->tokens->getAuthRequest($id);
        if (null === $auth) {
            return Response::html(Templates::error('The authorization request has expired. Please try again.'));
        }

        return Response::html(Templates::consent($id, (string) $auth['client_name'], (string) $auth['scope']));
    }

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }

        try {
            $result = $this->server->token($body);

            return Response::json($result, 200, ['Pragma' => 'no-cache']);
        } catch (OAuthException $e) {
            return Response::json([
                'error' => $e->error,
                'error_description' => $e->getMessage(),
            ], $e->status, ['Pragma' => 'no-cache']);
        }
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        if (!str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            return Response::json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Content-Type must be application/json.',
            ], 400);
        }

        try {
            $data = json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Response::json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Request body must be valid JSON.',
            ], 400);
        }

        if (!\is_array($data)) {
            return Response::json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Request body must be a JSON object.',
            ], 400);
        }

        try {
            $registration = $this->server->registerClient($data);
        } catch (RuntimeException $e) {
            return Response::json([
                'error' => 'invalid_client_metadata',
                'error_description' => $e->getMessage(),
            ], 400);
        }

        return Response::json($registration, 201);
    }

    private function handleConsentPost(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        $action = \is_array($body) && \is_string($body['action'] ?? null) ? (string) $body['action'] : 'deny';

        $auth = $this->tokens->getAuthRequest($id);
        if (null === $auth) {
            return Response::html(Templates::error('The authorization request has expired. Please try again.'));
        }

        if ('approve' !== $action) {
            $this->tokens->deleteAuthRequest($id);

            return $this->redirectWithParams($auth, ['error' => 'access_denied', 'error_description' => 'The user denied the request.']);
        }

        $user = $this->config->require('OAUTH_USERNAME');
        $code = $this->server->issueAuthorizationCode($auth, $user);
        $this->tokens->deleteAuthRequest($id);

        return $this->redirectWithParams($auth, ['code' => $code]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleAuthorizeError(array $params, OAuthException $e): ResponseInterface
    {
        $redirectUri = \is_string($params['redirect_uri'] ?? null) ? (string) $params['redirect_uri'] : '';
        $clientId = \is_string($params['client_id'] ?? null) ? (string) $params['client_id'] : '';
        $state = \is_string($params['state'] ?? null) ? (string) $params['state'] : '';

        if ('' !== $redirectUri && '' !== $clientId && $this->clients->isRedirectUriAllowed($clientId, $redirectUri)) {
            return $this->redirectWithParams([
                'redirect_uri' => $redirectUri,
                'state' => $state,
            ], ['error' => $e->error, 'error_description' => $e->getMessage()]);
        }

        return Response::html(Templates::error($e->getMessage()), 400);
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, string> $params
     */
    private function redirectWithParams(array $auth, array $params): ResponseInterface
    {
        $state = (string) ($auth['state'] ?? '');
        if ('' !== $state) {
            $params['state'] = $state;
        }
        $params['iss'] = $this->config->appUrl();

        return Response::redirect($this->appendQuery((string) $auth['redirect_uri'], $params));
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($params);
    }

    private function cookieHeader(string $value): string
    {
        $secure = str_starts_with(strtolower($this->config->appUrl()), 'https://') ? '; Secure' : '';

        return sprintf(
            '%s=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=%d%s',
            LoginSession::COOKIE,
            $value,
            28800,
            $secure,
        );
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();

        return (string) ($params['REMOTE_ADDR'] ?? 'unknown');
    }
}
