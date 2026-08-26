<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'mcp-cal-test-'.\bin2hex(\random_bytes(8));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (null !== $this->tempDir) {
            $this->removeDir($this->tempDir);
            $this->tempDir = null;
        }

        parent::tearDown();
    }

    protected function dataDir(): string
    {
        return $this->tempDir.\DIRECTORY_SEPARATOR.'data';
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
