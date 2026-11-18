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

    public function testScopeComesFromApiPath(): void
    {
        self::assertSame('articles', FileCache::scopeOf('abc123 /v2/articles?filter=valid%3Aeq%3Atrue'));
        self::assertSame('articles', FileCache::scopeOf('abc123 /v2/articles/42'));
        self::assertSame('web-parts', FileCache::scopeOf('abc123 /v2/web-parts'));
        self::assertSame('project', FileCache::scopeOf('abc123 /v2/project'));
        self::assertSame('other', FileCache::scopeOf('custom key'));
    }

    public function testClearScopesKeepsOtherResources(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('k /v2/articles?page=0', 'list');
        $cache->set('k /v2/articles/42', 'detail');
        $cache->set('k /v2/topics', 'topics');
        $cache->set('k /v2/project', 'project');

        self::assertSame(2, $cache->clearScopes(['articles', '../*', 'articles']));
        self::assertNull($cache->get('k /v2/articles?page=0'));
        self::assertNull($cache->get('k /v2/articles/42'));
        self::assertNotNull($cache->get('k /v2/topics'));
        self::assertNotNull($cache->get('k /v2/project'));
    }

    public function testClearScopesMatchesResourceNameExactly(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('k /v2/web/1', 'web');
        $cache->set('k /v2/web-parts', 'web parts');

        self::assertSame(1, $cache->clearScopes(['web']));
        self::assertNotNull($cache->get('k /v2/web-parts'));
    }

    public function testClearScopesAlsoRemovesEntriesOfUnknownResource(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('custom key', 'other');
        $cache->set('k /v2/topics', 'topics');

        $cache->clearScopes(['articles']);

        self::assertNull($cache->get('custom key'));
        self::assertNotNull($cache->get('k /v2/topics'));
    }

    public function testReadsEntriesWrittenByVersion10AndMovesThemUnderNewName(): void
    {
        $key = 'k /v2/articles/42';
        $this->writeVersion10Entry($key, ['id' => 42], time() - 100000);
        $cache = new FileCache($this->dir);

        $item = $cache->get($key);
        self::assertNotNull($item);
        self::assertSame(['id' => 42], $item->value);
        self::assertFalse($item->fresh);
        self::assertSame(['flexiapp-articles-' . sha1($key) . '.json'], array_map('basename', glob($this->dir . '/*') ?: []));

        $again = $cache->get($key);
        self::assertNotNull($again);
        self::assertSame($item->storedAt, $again->storedAt);

        // now the resource is known: other scopes keep it, its own scope drops it
        $cache->clearScopes(['sites']);
        self::assertNotNull($cache->get($key));
        $cache->clearScopes(['articles']);
        self::assertNull($cache->get($key));
    }

    public function testClearScopesRemovesUnreadEntriesWrittenByVersion10(): void
    {
        $key = 'k /v2/articles/42';
        $this->writeVersion10Entry($key, ['id' => 42], time());

        (new FileCache($this->dir))->clearScopes(['sites']);

        self::assertSame([], glob($this->dir . '/*'));
    }

    /** @param array<mixed> $value */
    private function writeVersion10Entry(string $key, array $value, int $storedAt): void
    {
        @mkdir($this->dir);
        file_put_contents($this->dir . '/flexiapp-' . sha1($key) . '.json', (string) json_encode(['storedAt' => $storedAt, 'key' => $key, 'value' => $value]));
    }

    public function testClearScopesRemovesLeftoverTemporaryFiles(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('k /v2/topics', 'topics');
        $old = $this->dir . '/flexiapp-articles-' . sha1('x') . '.json.abcd1234.tmp';
        $fresh = $this->dir . '/flexiapp-articles-' . sha1('y') . '.json.abcd1234.tmp';
        file_put_contents($old, '{');
        file_put_contents($fresh, '{');
        touch($old, time() - 3600);

        $cache->clearScopes(['articles']);

        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh); // may still be written right now
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
