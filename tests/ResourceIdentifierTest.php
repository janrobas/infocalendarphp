<?php

declare(strict_types=1);

namespace App\Tests;

use App\OAuth\ResourceIdentifier;

final class ResourceIdentifierTest extends TestCase
{
    public function testCanonicalizesSchemeHostAndDefaultPort(): void
    {
        self::assertSame(
            'https://calendar.example.com/mcp',
            ResourceIdentifier::canonicalize('HTTPS://Calendar.Example.com:443/mcp'),
        );
        self::assertSame(
            'http://calendar.example.com/mcp',
            ResourceIdentifier::canonicalize('http://calendar.example.com:80/mcp'),
        );
        self::assertSame(
            'https://calendar.example.com',
            ResourceIdentifier::canonicalize('https://calendar.example.com'),
        );
        self::assertSame(
            'https://calendar.example.com',
            ResourceIdentifier::canonicalize('https://calendar.example.com/'),
        );
        self::assertSame(
            'https://calendar.example.com:8443/mcp',
            ResourceIdentifier::canonicalize('https://calendar.example.com:8443/mcp'),
        );
    }

    public function testKeepsPathSignificant(): void
    {
        self::assertSame(
            'https://calendar.example.com/mcp/',
            ResourceIdentifier::canonicalize('https://calendar.example.com/mcp/'),
        );
        self::assertNotSame(
            ResourceIdentifier::canonicalize('https://calendar.example.com/mcp'),
            ResourceIdentifier::canonicalize('https://calendar.example.com/mcp/'),
        );
    }

    public function testRejectsUnsupportedForms(): void
    {
        foreach ([
            null,
            '',
            '/mcp',
            'ftp://calendar.example.com/mcp',
            'https://calendar.example.com/mcp?x=1',
            'https://calendar.example.com/mcp#fragment',
            'https://user:secret@calendar.example.com/mcp',
        ] as $value) {
            self::assertNull(ResourceIdentifier::canonicalize($value), (string) $value);
        }
    }
}
