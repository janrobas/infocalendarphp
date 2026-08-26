<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\FileLogger;
use App\Support\SecretRedactor;

final class RedactorTest extends TestCase
{
    public function testRedactsKnownSecrets(): void
    {
        $redactor = new SecretRedactor(['supersecretpassword', 'another-secret']);

        self::assertSame('password is [REDACTED] ok', $redactor->redact('password is supersecretpassword ok'));
        self::assertSame('[REDACTED] and [REDACTED]', $redactor->redact('supersecretpassword and another-secret'));
    }

    public function testIgnoresShortValues(): void
    {
        $redactor = new SecretRedactor(['ab', '123', 'ok']);

        self::assertSame('contains ab and 123', $redactor->redact('contains ab and 123'));
    }

    public function testIgnoresEmptyValues(): void
    {
        $redactor = new SecretRedactor(['', '   ', 'a-secret-value']);

        self::assertSame('[REDACTED]', $redactor->redact('a-secret-value'));
    }

    public function testFileLoggerRedactsMessageAndContext(): void
    {
        $path = $this->tempDir.'/app.log';
        $logger = new FileLogger($path, 100, new SecretRedactor(['s3cr3t-password']));

        $logger->error('Failed with s3cr3t-password', ['password' => 's3cr3t-password']);

        $contents = (string) file_get_contents($path);

        self::assertStringNotContainsString('s3cr3t-password', $contents);
        self::assertStringContainsString('[REDACTED]', $contents);
    }

    public function testFileLoggerWithoutRedactorWritesVerbatim(): void
    {
        $path = $this->tempDir.'/app.log';
        $logger = new FileLogger($path, 100);

        $logger->info('plain message');

        self::assertStringContainsString('plain message', (string) file_get_contents($path));
    }
}
