<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Webhook;

/** A verified webhook message. */
final class WebhookEvent
{
    public const CACHE_FLUSH = 'cache.flush';

    /**
     * @param string               $id        `webhook-id` header; the same for every delivery attempt, use it to deduplicate
     * @param string               $type      event type, e.g. `cache.flush`
     * @param string               $timestamp when the event happened (ISO 8601, UTC)
     * @param array<string, mixed> $data      event data; its shape depends on the type
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $timestamp,
        public readonly array $data,
    ) {
    }

    /** `cache.flush` sent by the "Test" action in the administration; it needs no work. */
    public function isTest(): bool
    {
        return ($this->data['reason'] ?? null) === 'test';
    }
}
