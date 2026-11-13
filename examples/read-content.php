<?php

/**
 * Lists the newest published articles and shows one of them.
 *
 *     FLEXIAPP_API_KEY=fxa_v2_… php examples/read-content.php
 *
 * Without an API key the example reads the sample data from tests/fixtures.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FlexiApp\ApiV2\ApiException;
use FlexiApp\ApiV2\Cache\FileCache;
use FlexiApp\ApiV2\Client;
use FlexiApp\ApiV2\Http\FixtureTransport;

$apiKey = (string) getenv('FLEXIAPP_API_KEY');
$client = $apiKey !== ''
    ? new Client($apiKey, new FileCache(sys_get_temp_dir() . '/flexiapp-example-cache'))
    : new Client('demo', transport: new FixtureTransport(__DIR__ . '/../tests/fixtures'));

try {
    $project = $client->project();
    echo "Project: {$project['name']}\n\n";

    $page = $client->list('articles', [
        'filter' => 'valid:eq:true',        // published only (not drafts, scheduled or expired)
        'sort' => 'publishedAt,desc',
        'size' => 5,
    ]);
    echo "Newest articles ({$page->totalElements} published):\n";
    foreach ($page->items as $article) {
        printf("  #%d %s (%s)\n", $article['id'], $article['name'], substr((string) $article['publishedAt'], 0, 10));
    }

    $first = $page->items[0] ?? null;
    if ($first !== null) {
        $detail = $client->find('articles', (int) $first['id']);
        echo "\nPerex of #{$first['id']}: " . ($detail['perex'] ?? '') . "\n";
    }

    echo "\nHTTP requests sent: {$client->requestCount()}\n";
} catch (ApiException $e) {
    fwrite(STDERR, 'FlexiApp error: ' . $e->getMessage() . "\n");
    exit(1);
}
