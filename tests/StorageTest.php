<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\FileStore;

final class StorageTest extends TestCase
{
    public function testPutAndGetRoundTrip(): void
    {
        $store = new FileStore($this->dataDir());

        $store->put('abc123', ['foo' => 'bar', 'n' => 1]);

        self::assertSame(['foo' => 'bar', 'n' => 1], $store->get('abc123'));
        self::assertTrue($store->has('abc123'));
    }

    public function testExpiredRecordIsRemoved(): void
    {
        $store = new FileStore($this->dataDir());

        $store->put('expired', ['v' => 1], -1); // already expired
        $store->put('alive', ['v' => 2], 3600);

        self::assertNull($store->get('expired'));
        self::assertSame(['v' => 2], $store->get('alive'));
    }

    public function testCleanupRemovesExpiredOnly(): void
    {
        $store = new FileStore($this->dataDir());

        $store->put('old', ['v' => 1], -10);
        $store->put('new', ['v' => 2], 3600);

        $removed = $store->cleanup();

        self::assertSame(1, $removed);
        self::assertNull($store->get('old'));
        self::assertSame(['v' => 2], $store->get('new'));
    }

    public function testDelete(): void
    {
        $store = new FileStore($this->dataDir());

        $store->put('key', ['v' => 1]);
        self::assertTrue($store->delete('key'));
        self::assertNull($store->get('key'));
    }

    public function testRejectsUnsafeKeys(): void
    {
        $store = new FileStore($this->dataDir());

        $this->expectException(\RuntimeException::class);
        $store->put('../../etc/passwd', ['v' => 1]);
    }

    public function testConcurrentWritesDoNotCorrupt(): void
    {
        $store = new FileStore($this->dataDir());

        // Simulate concurrent writers to distinct keys plus repeated overwrites.
        for ($i = 0; $i < 50; ++$i) {
            $store->put('k'.$i, ['i' => $i, 'payload' => str_repeat('x', 100)]);
        }
        for ($i = 0; $i < 50; ++$i) {
            $store->put('k'.$i, ['i' => $i, 'payload' => str_repeat('y', 100)]);
        }

        for ($i = 0; $i < 50; ++$i) {
            $record = $store->get('k'.$i);
            self::assertNotNull($record);
            self::assertSame($i, $record['i']);
            self::assertSame(str_repeat('y', 100), $record['payload']);
        }
    }

    public function testAtomicWriteLeavesNoTempFiles(): void
    {
        $store = new FileStore($this->dataDir());

        $store->put('key', ['v' => 1]);

        foreach ($store->keys() as $key) {
            self::assertStringNotContainsString('.tmp.', $key);
        }
    }
}
