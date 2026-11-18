<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests\Webhook;

use FlexiApp\ApiV2\Cache\CacheInterface;
use FlexiApp\ApiV2\Cache\CacheItem;
use FlexiApp\ApiV2\Cache\FileCache;
use FlexiApp\ApiV2\Webhook\Webhook;
use FlexiApp\ApiV2\Webhook\WebhookEvent;
use FlexiApp\ApiV2\Webhook\WebhookHandler;
use PHPUnit\Framework\TestCase;

final class WebhookHandlerTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private string $dir;
    private FileCache $cache;
    private Webhook $webhook;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/flexiapp-webhook-test-' . bin2hex(random_bytes(4));
        $this->cache = new FileCache($this->dir);
        $this->cache->set('response', ['cached' => true]);
        $this->webhook = new Webhook(self::SECRET);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testCacheFlushClearsCache(): void
    {
        $status = (new WebhookHandler($this->webhook, $this->cache))->handle(...$this->request('cache.flush', 'auto'));

        self::assertSame(204, $status);
        self::assertNull($this->cache->get('response'));
    }

    public function testTestDeliveryKeepsCache(): void
    {
        $status = (new WebhookHandler($this->webhook, $this->cache))->handle(...$this->request('cache.flush', 'test'));

        self::assertSame(204, $status);
        self::assertNotNull($this->cache->get('response'));
    }

    public function testUnknownEventIsAcknowledged(): void
    {
        $status = (new WebhookHandler($this->webhook, $this->cache))->handle(...$this->request('article.published'));

        self::assertSame(204, $status);
        self::assertNotNull($this->cache->get('response'));
    }

    public function testInvalidSignatureIsRejected(): void
    {
        [$payload, $headers] = $this->request('cache.flush', 'manual');
        $headers['webhook-signature'] = 'v1,' . base64_encode(random_bytes(32));

        self::assertSame(401, (new WebhookHandler($this->webhook, $this->cache))->handle($payload, $headers));
        self::assertNotNull($this->cache->get('response'));
    }

    public function testListenersReceiveEvents(): void
    {
        $received = [];
        $handler = (new WebhookHandler($this->webhook))
            ->on('cache.flush', static function (WebhookEvent $event) use (&$received): void {
                $received[] = $event->data['reason'] ?? null;
            });

        $handler->handle(...$this->request('cache.flush', 'manual'));

        self::assertSame(['manual'], $received);
    }

    public function testScopesClearOnlyChangedResources(): void
    {
        $this->warmUp();

        (new WebhookHandler($this->webhook, $this->cache))->handle(...$this->request('cache.flush', 'auto', ['articles']));

        self::assertSame(['products', 'project', 'sites', 'topics'], $this->cachedScopes());
    }

    public function testTopicChangeAlsoClearsDocumentsThatEmbedTopics(): void
    {
        $this->warmUp();

        (new WebhookHandler($this->webhook, $this->cache))->handle(...$this->request('cache.flush', 'auto', ['topics', 'sites']));

        self::assertSame(['project'], $this->cachedScopes());
    }

    public function testScopesToFlushReportsWhatIsCleared(): void
    {
        $handler = new WebhookHandler($this->webhook, $this->cache);
        $event = static fn (?array $scopes): WebhookEvent => new WebhookEvent('msg_1', 'cache.flush', '', array_filter(['reason' => 'auto', 'scopes' => $scopes]));

        self::assertSame(['articles'], $handler->scopesToFlush($event(['articles'])));
        self::assertSame(['topics', 'articles', 'products', 'profiles', 'gallery-images'], $handler->scopesToFlush($event(['topics'])));
        self::assertSame(['articles', 'topics', 'products', 'profiles', 'gallery-images'], $handler->scopesToFlush($event(['articles', 'topics'])));
        self::assertNull($handler->scopesToFlush($event(['articles', 'menus'])));
        self::assertNull($handler->scopesToFlush($event(null)));
        self::assertNull((new WebhookHandler($this->webhook, $this->cache, partialFlush: false))->scopesToFlush($event(['articles'])));
        self::assertNull((new WebhookHandler($this->webhook))->scopesToFlush($event(['articles'])));
    }

    public function testUnknownScopeClearsEverything(): void
    {
        $this->warmUp();

        (new WebhookHandler($this->webhook, $this->cache))->handle(...$this->request('cache.flush', 'auto', ['articles', 'menus']));

        self::assertSame([], $this->cachedScopes());
    }

    public function testProjectScopeClearsEverything(): void
    {
        $this->warmUp();

        (new WebhookHandler($this->webhook, $this->cache))->handle(...$this->request('cache.flush', 'auto', ['project']));

        self::assertSame([], $this->cachedScopes());
    }

    public function testPartialFlushCanBeTurnedOff(): void
    {
        $this->warmUp();

        (new WebhookHandler($this->webhook, $this->cache, partialFlush: false))->handle(...$this->request('cache.flush', 'auto', ['articles']));

        self::assertSame([], $this->cachedScopes());
    }

    public function testCacheWithoutScopesIsClearedCompletely(): void
    {
        $cache = new class () implements CacheInterface {
            public int $cleared = 0;

            public function get(string $key): ?CacheItem
            {
                return null;
            }

            public function set(string $key, mixed $value): void
            {
            }

            public function clear(): int
            {
                $this->cleared++;

                return 0;
            }
        };

        (new WebhookHandler($this->webhook, $cache))->handle(...$this->request('cache.flush', 'auto', ['articles']));

        self::assertSame(1, $cache->cleared);
    }

    private function warmUp(): void
    {
        $this->cache->clear();
        foreach (['articles?page=0', 'articles/42', 'products', 'topics', 'sites', 'project'] as $path) {
            $this->cache->set('key /v2/' . $path, ['cached' => $path]);
        }
    }

    /** @return list<string> resources that still have cached responses */
    private function cachedScopes(): array
    {
        $scopes = [];
        foreach (['articles?page=0', 'articles/42', 'products', 'topics', 'sites', 'project'] as $path) {
            if ($this->cache->get('key /v2/' . $path) !== null) {
                $scopes[] = FileCache::scopeOf('key /v2/' . $path);
            }
        }
        sort($scopes);

        return array_values(array_unique($scopes));
    }

    /**
     * @param list<string>|null $scopes
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function request(string $type, ?string $reason = null, ?array $scopes = null): array
    {
        $payload = (string) json_encode([
            'type' => $type,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => array_filter(['projectId' => 1, 'reason' => $reason, 'scopes' => $scopes]),
        ]);
        $id = 'msg_' . bin2hex(random_bytes(12));
        $time = time();

        return [$payload, [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $time,
            'webhook-signature' => $this->webhook->sign($id, $time, $payload),
        ]];
    }
}
