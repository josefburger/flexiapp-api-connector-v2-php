<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests\Cache;

use FlexiApp\ApiV2\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/flexiapp-cache-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testStoresAndReadsFreshValues(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('a', ['name' => 'Žluťoučký kůň']);

        $item = $cache->get('a');
        self::assertNotNull($item);
        self::assertTrue($item->fresh);
        self::assertSame(['name' => 'Žluťoučký kůň'], $item->value);
        self::assertNull($cache->get('missing'));
    }

    public function testExpiredValuesStayAvailable(): void
    {
        $cache = new FileCache($this->dir, ttl: -1);
        $cache->set('a', [1]);

        $item = $cache->get('a');
        self::assertNotNull($item);
        self::assertFalse($item->fresh);
        self::assertSame([1], $item->value);
    }

    public function testDefaultTtlIsOneDay(): void
    {
        self::assertSame(86400, FileCache::DEFAULT_TTL);
    }

    public function testClearRemovesOnlyOwnEntries(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('a', 1);
        $cache->set('b', 2);

        file_put_contents($this->dir . '/unrelated.json', '{}');

        self::assertSame(2, $cache->clear());
        self::assertNull($cache->get('a'));
        self::assertFileExists($this->dir . '/unrelated.json');
        self::assertSame(0, (new FileCache($this->dir . '/does-not-exist'))->clear());
    }
}
