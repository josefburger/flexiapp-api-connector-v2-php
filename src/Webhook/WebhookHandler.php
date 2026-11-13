<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Webhook;

use FlexiApp\ApiV2\Cache\CacheInterface;

/**
 * Ready-made webhook endpoint: verifies the request, clears the response
 * cache on `cache.flush` and answers with the right HTTP status.
 *
 *     // public/flexiapp-webhook.php
 *     $handler = new WebhookHandler(new Webhook('whsec_…'), new FileCache(__DIR__ . '/../var/cache/flexiapp'));
 *     $handler->handleRequest();
 *
 * Status codes: 204 handled (also for unknown event types, as the
 * documentation requires), 401 invalid signature or timestamp, 405 not a
 * POST request. Clearing the cache is idempotent, so repeated deliveries of
 * the same message are harmless and need no deduplication.
 */
final class WebhookHandler
{
    /** @var array<string, list<callable(WebhookEvent): void>> */
    private array $listeners = [];

    public function __construct(
        private readonly Webhook $webhook,
        private readonly ?CacheInterface $cache = null,
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
            $this->cache?->clear();
        }

        foreach ($this->listeners[$event->type] ?? [] as $listener) {
            $listener($event);
        }

        return 204;
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
