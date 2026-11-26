<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * Canonical form for RFC 8707 resource indicators.
 *
 * The MCP endpoints are plain http(s) URLs, so anything that relies on
 * user-info, a query string or a fragment is rejected instead of being
 * silently folded into the endpoint's resource. Scheme and host are
 * case-insensitive; a default port and an empty path are not significant.
 * The path itself is kept exactly as sent, so `/mcp` and `/mcp/` are
 * different resources, matching the routes the front controller serves.
 */
final class ResourceIdentifier
{
    /**
     * @return string|null the canonical identifier, or null when the value is
     *                     not an absolute http(s) URI this server can serve
     */
    public static function canonicalize(?string $resource): ?string
    {
        $resource = trim((string) $resource);
        if ('' === $resource) {
            return null;
        }

        $parts = parse_url($resource);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if ('' === $host) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port)) {
            $port = null;
        }

        // RFC 3986 treats an empty path and "/" as the same for http(s) URIs
        // with an authority, so the bare APP_URL is accepted either way.
        $path = $parts['path'] ?? '';
        if ('/' === $path) {
            $path = '';
        }

        return $scheme.'://'.$host.(null !== $port ? ':'.$port : '').$path;
    }
}
