<?php

declare(strict_types=1);

namespace App\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

/**
 * Small PSR-7 response helpers plus a SAPI emitter that also handles SSE
 * (non-seekable callback) streams returned by the MCP transport.
 */
final class Response
{
    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }

    /**
     * @param array<string, mixed>    $data
     * @param array<string, string>   $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $factory = self::factory();

        $response = $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($factory->createStream(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return self::secure($response);
    }

    public static function html(string $html, int $status = 200): ResponseInterface
    {
        $factory = self::factory();

        return self::secure(
            $factory->createResponse($status)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withBody($factory->createStream($html)),
        );
    }

    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        $factory = self::factory();

        return self::secure(
            $factory->createResponse($status)
                ->withHeader('Location', $url),
        );
    }

    /**
     * Apply security headers to a response.
     */
    public static function secure(ResponseInterface $response): ResponseInterface
    {
        $isHtml = str_contains($response->getHeaderLine('Content-Type'), 'text/html');

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Security-Policy', $isHtml
                ? "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
                : "default-src 'none'");
    }

    /**
     * Emit a PSR-7 response to the SAPI, supporting both seekable (JSON/HTML)
     * and non-seekable (SSE callback) bodies.
     */
    public static function emit(ResponseInterface $response): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($response->getStatusCode());

        $hasContentLength = false;
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                if (strtolower($name) === 'content-length') {
                    $hasContentLength = true;
                }
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
            $contents = $body->getContents();
            if (!$hasContentLength && $contents !== '') {
                header('Content-Length: '.strlen($contents));
            }
            echo $contents;

            return;
        }

        // Non-seekable stream (SSE): the read triggers the underlying callback
        // which writes directly to the output with echo()/flush().
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            echo $chunk;
        }
    }
}
