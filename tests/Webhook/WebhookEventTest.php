<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests\Webhook;

use FlexiApp\ApiV2\Webhook\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    public function testScopes(): void
    {
        self::assertSame(['articles', 'topics'], $this->event(['reason' => 'auto', 'scopes' => ['articles', 'topics', 'articles']])->scopes());
    }

    public function testMissingOrMalformedScopesMeanEverything(): void
    {
        self::assertNull($this->event(['reason' => 'auto'])->scopes());
        self::assertNull($this->event(['reason' => 'auto', 'scopes' => []])->scopes());
        self::assertNull($this->event(['reason' => 'auto', 'scopes' => 'articles'])->scopes());
        self::assertNull($this->event(['reason' => 'auto', 'scopes' => ['articles', 42]])->scopes());
    }

    public function testTestDelivery(): void
    {
        self::assertTrue($this->event(['reason' => 'test'])->isTest());
        self::assertFalse($this->event(['reason' => 'manual'])->isTest());
    }

    /** @param array<string, mixed> $data */
    private function event(array $data): WebhookEvent
    {
        return new WebhookEvent('msg_1', WebhookEvent::CACHE_FLUSH, '2026-11-16T10:00:00Z', $data);
    }
}
