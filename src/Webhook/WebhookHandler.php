<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Webhook;

use FlexiApp\ApiV2\Cache\CacheInterface;
use FlexiApp\ApiV2\Cache\ScopedCacheInterface;

/**
 * Ready-made webhook endpoint: verifies the request, clears the response
 * cache on `cache.flush` and answers with the right HTTP status.
 *
 *     // public/flexiapp-webhook.php
 *     $handler = new WebhookHandler(new Webhook('whsec_…'), new FileCache(__DIR__ . '/../var/cache/flexiapp'));
 *     $handler->handleRequest();
 *
 * When the event lists `scopes` (e.g. only `articles` changed) and the
 * cache supports it ({@see ScopedCacheInterface}, like `FileCache`), only
 * those resources are dropped and the rest of the cache stays warm. A topic
 * change also drops the documents, because they embed their topics. Without
 * scopes, with an unknown scope or with a plain cache, everything is cleared.
 *
 * Status codes: 204 handled (also for unknown event types, as the
 * documentation requires), 401 invalid signature or timestamp, 405 not a
 * POST request. Clearing the cache is idempotent, so repeated deliveries of
 * the same message are harmless and need no deduplication.
 */
final class WebhookHandler
{
    /** Scopes FlexiApp may send; anything else means "clear everything". */
    public const KNOWN_SCOPES = ['articles', 'products', 'profiles', 'gallery-images', 'sites', 'topics', 'web-parts'];

    /**
     * Resources whose responses embed data of another resource. According to
     * the API schema only topics are embedded (articles, products, profiles
     * and gallery images carry `topics: [{id, name, urlPart}]`); other links,
     * such as products in an article's `products` part, are plain IDs.
     */
    private const DEPENDENT_SCOPES = [
        'topics' => ['articles', 'products', 'profiles', 'gallery-images'],
    ];

    /** @var array<string, list<callable(WebhookEvent): void>> */
    private array $listeners = [];

    /**
     * @param bool $partialFlush clear only the resources listed in `scopes`; false = always clear everything
     */
    public function __construct(
        private readonly Webhook $webhook,
        private readonly ?CacheInterface $cache = null,
        private readonly bool $partialFlush = true,
    ) {
    }

    /**
     * Registers an extra listener for an event type (runs after the built-in
     * cache flush). Keep listeners fast: FlexiApp waits at most 10 seconds.
     *
     * @param callable(WebhookEvent): void $listener
     */
    public function on(string $type, callable $listener): self
    {
        $this->listeners[$type][] = $listener;

        return $this;
    }

    /**
     * Processes one webhook request and returns the HTTP status to answer with.
     *
     * @param array<string, string> $headers
     */
    public function handle(string $payload, array $headers): int
    {
        try {
            $event = $this->webhook->verify($payload, $headers);
        } catch (WebhookException $e) {
            error_log('FlexiApp webhook rejected: ' . $e->getMessage());

            return 401;
        }

        if ($event->type === WebhookEvent::CACHE_FLUSH && !$event->isTest()) {
            $this->flush($event);
        }

        foreach ($this->listeners[$event->type] ?? [] as $listener) {
            $listener($event);
        }

        return 204;
    }

    /**
     * What a `cache.flush` event clears: a list of resources, or null for the
     * whole cache. Unlike {@see WebhookEvent::scopes()} (what FlexiApp sent),
     * this already includes dependent resources and falls back to null for
     * unknown scopes, a cache without {@see ScopedCacheInterface} or
     * `partialFlush: false`. Use it in listeners that keep their own caches.
     *
     * @return list<string>|null
     */
    public function scopesToFlush(WebhookEvent $event): ?array
    {
        $scopes = $this->partialFlush ? $event->scopes() : null;
        if ($scopes === null || !$this->cache instanceof ScopedCacheInterface || array_diff($scopes, self::KNOWN_SCOPES) !== []) {
            return null;
        }

        $dependents = array_map(static fn (string $scope): array => self::DEPENDENT_SCOPES[$scope] ?? [], $scopes);

        return array_values(array_unique(array_merge($scopes, ...$dependents)));
    }

    private function flush(WebhookEvent $event): void
    {
        $scopes = $this->scopesToFlush($event);
        if ($scopes === null || !$this->cache instanceof ScopedCacheInterface) {
            $this->cache?->clear();

            return;
        }
        $this->cache->clearScopes($scopes);
    }

    /**
     * Handles the current PHP request (`php://input`, `$_SERVER`) and sends
     * the response status. Returns the status for logging.
     */
    public function handleRequest(): int
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            http_response_code(405);

            return 405;
        }

        $headers = [];
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
            $headers[$name] = (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
        }

        $status = $this->handle((string) file_get_contents('php://input'), $headers);
        http_response_code($status);

        return $status;
    }
}
