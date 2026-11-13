<?php

/**
 * Webhook endpoint that clears the response cache whenever FlexiApp asks for it.
 *
 * Put a file like this into your document root (for example as
 * /flexiapp/webhook), register its https URL in the FlexiApp administration
 * and use the same cache directory as your Client.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FlexiApp\ApiV2\Cache\FileCache;
use FlexiApp\ApiV2\Webhook\Webhook;
use FlexiApp\ApiV2\Webhook\WebhookEvent;
use FlexiApp\ApiV2\Webhook\WebhookHandler;

$handler = new WebhookHandler(
    new Webhook((string) getenv('FLEXIAPP_WEBHOOK_SECRET')),   // "whsec_…"
    new FileCache(__DIR__ . '/../var/cache/flexiapp'),
);

// Optional: react to events yourself, e.g. log every flush.
$handler->on(WebhookEvent::CACHE_FLUSH, static function (WebhookEvent $event): void {
    $reason = $event->data['reason'] ?? '-';
    error_log("FlexiApp cache flush ({$reason}), message {$event->id}");
});

$handler->handleRequest();
