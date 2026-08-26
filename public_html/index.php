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
use Http\Discovery\Psr17Factory;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;

require __DIR__.'/../vendor/autoload.php';

date_default_timezone_set('UTC');

// Never buffer output: the MCP transport streams SSE responses.
while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', '0');

$baseDir = dirname(__DIR__);

$config = Config::load($baseDir);
$dataDir = $config->dataDir($baseDir);

$logger = new FileLogger($dataDir.'/app.log', $config->isDebug() ? 100 : 200);

$psr17 = new Psr17Factory();
$request = $psr17->createServerRequestFromGlobals();

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

$calendar = new CalendarService(CalDavClient::create(
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

$mcpServer = McpServer::build($calendar, $dataDir.'/sessions');

try {
    $response = route($request, $config, $oauth, $authServer, $metadata, $calendar, $tokenValidator, $mcpServer);
} catch (Throwable $e) {
    $logger->error('Unhandled error.', ['exception' => $e]);

    $response = $config->isDebug()
        ? Response::text('Internal Server Error: '.$e->getMessage()."\n\n".$e->getTraceAsString(), 500)
        : Response::json(['error' => 'internal_error'], 500);
}

Response::emit($response);

/**
 * @return ResponseInterface
 */
function route(
    \Psr\Http\Message\ServerRequestInterface $request,
    Config $config,
    OAuthController $oauth,
    AuthorizationServer $authServer,
    ProtectedResourceMetadata $metadata,
    CalendarService $calendar,
    TokenValidator $tokenValidator,
    \Mcp\Server $mcpServer,
) {
    $method = $request->getMethod();
    $path = $request->getUri()->getPath();

    if ('GET' === $method && '/' === $path) {
        return Response::json([
            'service' => 'Infomaniak Calendar MCP',
            'health' => $config->appUrl().'/health',
            'mcp' => $config->mcpUrl(),
        ]);
    }

    if ('GET' === $method && '/health' === $path) {
        return Response::json(['status' => 'ok']);
    }

    if ('GET' === $method && '/health/caldav' === $path) {
        try {
            $calendar->listCalendars();

            return Response::json(['status' => 'ok']);
        } catch (Throwable) {
            return Response::json(['status' => 'unavailable'], 503);
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

    if ('/mcp' === $path) {
        return handleMcp($request, $config, $metadata, $tokenValidator, $mcpServer);
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
    $host = strtolower((string) parse_url($config->appUrl(), \PHP_URL_HOST));

    $transport = new StreamableHttpTransport(
        $request,
        middleware: [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware([$host]),
            new AuthorizationMiddleware($tokenValidator, $metadata),
        ],
    );

    return Response::secure($mcpServer->run($transport));
}
