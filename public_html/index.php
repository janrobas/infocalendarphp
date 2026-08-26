<?php

declare(strict_types=1);

use App\Auth\LoginSession;
use App\Auth\RateLimiter;
use App\Calendar\CalDavClient;
use App\Calendar\CalendarService;
use App\Config;
use App\Http\OAuthController;
use App\Http\Response;
use App\Mcp\McpServer;
use App\OAuth\AuthorizationServer;
use App\OAuth\ClientStore;
use App\OAuth\TokenStore;
use App\OAuth\TokenValidator;
use App\Storage\FileStore;
use App\Support\FileLogger;
use App\Support\SecretRedactor;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;

/**
 * Read APP_DEBUG directly from .env (before Composer loads) so startup errors
 * can be surfaced to the operator instead of returning a blank 500.
 */
function app_is_debug(string $envFile): bool
{
    if (!is_file($envFile)) {
        return false;
    }

    $lines = @file($envFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
    if (false === $lines) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ('' === $line || '#' === $line[0]) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        if ('APP_DEBUG' === trim($key)) {
            return \in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
    }

    return false;
}

/**
 * Collect the secret values from .env (and the real environment) so they can
 * be redacted from any error output or log. Runs before Composer loads.
 *
 * @return list<string>
 */
function app_secrets(string $envFile): array
{
    $secrets = [];

    if (is_file($envFile)) {
        $lines = @file($envFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false !== $lines) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ('' === $line || '#' === $line[0]) {
                    continue;
                }

                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $key = trim($key);
                $value = trim($value);

                // Strip surrounding quotes, leniently (handles a single
                // unmatched quote such as a forgotten closing quote).
                if (strlen($value) >= 1 && ('"' === $value[0] || "'" === $value[0])) {
                    $value = substr($value, 1);
                }
                if (strlen($value) >= 1 && ('"' === substr($value, -1) || "'" === substr($value, -1))) {
                    $value = substr($value, 0, -1);
                }

                if ('' === $value || 1 === preg_match('/^\d+$/', $value)) {
                    continue;
                }

                if (1 === preg_match('/(password|secret|token|private|_key)/i', $key)) {
                    $secrets[] = $value;
                }
            }
        }
    }

    foreach (['INFOMANIAK_APP_PASSWORD', 'OAUTH_PASSWORD_HASH'] as $name) {
        $value = getenv($name);
        if (\is_string($value) && '' !== $value) {
            $secrets[] = $value;
        }
    }

    return array_values(array_unique($secrets));
}

/**
 * Redact secret values from a string. Plain PHP (no Composer) so it also works
 * when the autoloader itself failed.
 *
 * @param list<string> $secrets
 */
function app_redact(string $text, array $secrets): string
{
    foreach ($secrets as $secret) {
        if (strlen($secret) >= 4) {
            $text = str_replace($secret, '[REDACTED]', $text);
        }
    }

    return $text;
}

date_default_timezone_set('UTC');

$baseDir = dirname(__DIR__);
$envFile = $baseDir.'/.env';

$isDebug = app_is_debug($envFile);
$secrets = app_secrets($envFile);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Never buffer output: the MCP transport streams SSE responses.
while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', '0');

try {
    $autoload = $baseDir.'/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new \RuntimeException(
            'vendor/autoload.php is missing. The vendor/ directory was not uploaded. '
            .'Run "composer install --no-dev" locally and upload the vendor/ folder.'
        );
    }

    require $autoload;

    $config = Config::load($baseDir);
    $dataDir = $config->dataDir($baseDir);

    $redactor = new SecretRedactor($secrets);
    $logger = new FileLogger($dataDir.'/app.log', $isDebug ? 100 : 200, $redactor);

    $psr17 = new Psr17Factory();
    $request = $psr17->createServerRequestFromGlobals();

    // Proxies terminate TLS, so $_SERVER['HTTPS']/SERVER_PORT are often wrong
    // even though the public site is HTTPS. Force the scheme and port from
    // APP_URL so the OAuth metadata URL in the WWW-Authenticate header is
    // correct (ChatGPT rejects a wrong scheme or a dangling port).
    $appUrlParts = parse_url($config->appUrl());
    if (\is_array($appUrlParts) && isset($appUrlParts['scheme'])) {
        $uri = $request->getUri()->withScheme(strtolower($appUrlParts['scheme']));
        $uri = $uri->withPort(isset($appUrlParts['port']) ? (int) $appUrlParts['port'] : null);
        $request = $request->withUri($uri);
    }

    $clients = new ClientStore(new FileStore($dataDir.'/oauth/clients'));
    $tokens = TokenStore::create($dataDir);
    $authServer = new AuthorizationServer($config, $clients, $tokens);

    // Opportunistic garbage collection of expired OAuth records (~1% of requests).
    if (1 === mt_rand(1, 100)) {
        $tokens->cleanup();
    }

    $sessionKey = LoginSession::loadOrCreateKey($dataDir);
    $sessions = new LoginSession($sessionKey);
    $rateLimiter = new RateLimiter(new FileStore($dataDir.'/rate_limit'));

    $oauth = new OAuthController($authServer, $clients, $tokens, $config, $sessions, $rateLimiter);

    // Built lazily so /health and the OAuth discovery endpoints keep working
    // even if the CalDAV / MCP services cannot be constructed on this host.
    $calendarFactory = static fn (): CalendarService => new CalendarService(CalDavClient::create(
        $config->get('INFOMANIAK_CALDAV_URL', 'https://sync.infomaniak.com/') ?? 'https://sync.infomaniak.com/',
        $config->get('INFOMANIAK_USERNAME', '') ?? '',
        $config->get('INFOMANIAK_APP_PASSWORD', '') ?? '',
    ));

    $tokenValidator = new TokenValidator($tokens, $authServer);
    $metadata = new ProtectedResourceMetadata(
        authorizationServers: [$config->appUrl()],
        scopesSupported: [AuthorizationServer::SCOPE],
        resource: $config->mcpUrl(),
        resourceName: 'Infomaniak Calendar',
    );

    $mcpFactory = static fn (): \Mcp\Server => McpServer::build($calendarFactory(), $dataDir.'/sessions');

    $response = route($request, $config, $oauth, $authServer, $metadata, $calendarFactory, $tokenValidator, $mcpFactory);

    Response::emit($response);
} catch (\Throwable $e) {
    $details = app_redact((string) $e, $secrets);

    error_log($details);
    @file_put_contents($baseDir.'/data/app.log', '[error] '.$details.\PHP_EOL, \FILE_APPEND | \LOCK_EX);

    http_response_code(500);
    if ($isDebug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Internal Server Error\n\n".$details;
    } else {
        header('Content-Type: application/json');
        echo '{"error":"internal_error"}';
    }
}

/**
 * @param \Closure(): CalendarService $calendarFactory
 * @param \Closure(): \Mcp\Server      $mcpFactory
 *
 * @return ResponseInterface
 */
function route(
    \Psr\Http\Message\ServerRequestInterface $request,
    Config $config,
    OAuthController $oauth,
    AuthorizationServer $authServer,
    ProtectedResourceMetadata $metadata,
    \Closure $calendarFactory,
    TokenValidator $tokenValidator,
    \Closure $mcpFactory,
) {
    $method = $request->getMethod();
    $path = $request->getUri()->getPath();

    if ('GET' === $method && '/health' === $path) {
        return Response::json(['status' => 'ok']);
    }

    if ('GET' === $method && '/health/caldav' === $path) {
        $calendar = $calendarFactory();

        try {
            $calendar->listCalendars();

            return Response::json(['status' => 'ok']);
        } catch (\App\Calendar\CalDavException $e) {
            $data = ['status' => 'unavailable', 'error' => $e->getMessage()];
            if ($config->isDebug()) {
                $data['trace'] = $calendar->debugTrace();
                $data['probe'] = $calendar->debugDiscovery();
            }

            return Response::json($data, 503);
        } catch (Throwable) {
            return Response::json(['status' => 'unavailable', 'error' => 'Calendar server unavailable.'], 503);
        }
    }

    if ('GET' === $method && '/.well-known/oauth-protected-resource' === $path) {
        return Response::json($metadata->jsonSerialize());
    }

    if ('GET' === $method
        && ('/.well-known/oauth-authorization-server' === $path || '/.well-known/openid-configuration' === $path)) {
        return Response::json($authServer->discovery());
    }

    if ('POST' === $method && '/register' === $path) {
        return $oauth->register($request);
    }

    if ('GET' === $method && '/authorize' === $path) {
        return $oauth->authorize($request);
    }

    if ('POST' === $method && '/login' === $path) {
        return $oauth->login($request);
    }

    if ('/consent' === $path && ('GET' === $method || 'POST' === $method)) {
        return $oauth->consent($request);
    }

    if ('POST' === $method && '/token' === $path) {
        return $oauth->token($request);
    }

    // The MCP endpoint is /mcp, but accept the base URL too. Some clients
    // (including ChatGPT's connector) probe the bare domain, so both must
    // answer with the OAuth challenge instead of a 200 landing page.
    if ('/mcp' === $path || '/' === $path) {
        return handleMcp($request, $config, $metadata, $tokenValidator, $mcpFactory());
    }

    return Response::json(['error' => 'not_found'], 404);
}

/**
 * @return ResponseInterface
 */
function handleMcp(
    \Psr\Http\Message\ServerRequestInterface $request,
    Config $config,
    ProtectedResourceMetadata $metadata,
    TokenValidator $tokenValidator,
    \Mcp\Server $mcpServer,
) {
    // Note: no DnsRebindingProtectionMiddleware here. This is a public HTTPS
    // server already gated by OAuth Bearer tokens and fronted by a web server
    // that validates Host; strict Origin checking would reject ChatGPT's
    // connector (which may send an Origin header).
    $transport = new StreamableHttpTransport(
        $request,
        middleware: [
            new CorsMiddleware(),
            new AuthorizationMiddleware($tokenValidator, $metadata),
        ],
    );

    return Response::secure($mcpServer->run($transport));
}
