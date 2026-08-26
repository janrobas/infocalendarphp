<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\LoginSession;

final class AuthTest extends TestCase
{
    public function testSessionRoundTrip(): void
    {
        $session = new LoginSession('secret');

        $cookie = $session->issue('jan');
        self::assertSame('jan', $session->verify($cookie));
    }

    public function testSessionRejectsTamperedSignature(): void
    {
        $session = new LoginSession('secret');

        $cookie = $session->issue('jan');
        $tampered = substr($cookie, 0, -1).('a' === substr($cookie, -1) ? 'b' : 'a');

        self::assertNull($session->verify($tampered));
    }

    public function testSessionExpired(): void
    {
        $session = new LoginSession('secret');

        $cookie = $session->issue('jan', -1);

        self::assertNull($session->verify($cookie));
    }

    public function testSessionRejectsNull(): void
    {
        $session = new LoginSession('secret');

        self::assertNull($session->verify(null));
        self::assertNull($session->verify(''));
    }
}
