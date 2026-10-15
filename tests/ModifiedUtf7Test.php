<?php

declare(strict_types=1);

namespace App\Tests;

use App\Mail\ModifiedUtf7;

final class ModifiedUtf7Test extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mailboxNames(): iterable
    {
        yield 'ASCII name is left alone' => ['INBOX', 'INBOX'];
        yield 'Slovenian name' => ['&AWA-CKR TODO', 'ŠCKR TODO'];
        yield 'nested path keeps its delimiter' => ['INBOX/&AWA-CKR TODO', 'INBOX/ŠCKR TODO'];
        yield 'shifted sequence followed by a dash' => ['&AWA--', 'Š-'];
        yield 'German umlauts' => ['Entw&APw-rfe', 'Entwürfe'];
        yield 'emoji outside the basic plane' => ['&2D3eAA-', '😀'];
        yield 'escaped ampersand' => ['Tom &- Jerry', 'Tom & Jerry'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readableNames(): iterable
    {
        yield 'readable name with non-ASCII characters' => ['ŠCKR TODO'];
        yield 'readable name with an ampersand' => ['Tom & Jerry'];
        yield 'trailing ampersand' => ['Naboj &'];
        yield 'unterminated shift sequence' => ['&AWA'];
        yield 'shift sequence with an invalid payload' => ['&A A-'];
        yield 'ampersand only' => ['&'];
        yield 'empty name' => [''];
    }

    /**
     * @dataProvider mailboxNames
     */
    public function testDecodesWireNameToReadableName(string $wire, string $readable): void
    {
        self::assertSame($readable, ModifiedUtf7::decode($wire));
    }

    /**
     * @dataProvider readableNames
     */
    public function testLeavesReadableNameUntouched(string $readable): void
    {
        self::assertSame($readable, ModifiedUtf7::decode($readable));
    }

    public function testDecodingIsSymmetricWithTheImapLibrary(): void
    {
        foreach (['ŠCKR TODO', 'INBOX/ŠCKR TODO', 'Entwürfe', 'Gráficos & notas', '😀', 'INBOX'] as $name) {
            $wire = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');

            self::assertSame($name, ModifiedUtf7::decode($wire));
        }
    }
}
