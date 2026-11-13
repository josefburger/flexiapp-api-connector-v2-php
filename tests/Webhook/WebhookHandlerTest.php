<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests\Webhook;

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

    /** @return array{0: string, 1: array<string, string>} */
    private function request(string $type, ?string $reason = null): array
    {
        $payload = (string) json_encode([
            'type' => $type,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => array_filter(['projectId' => 1, 'reason' => $reason]),
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
