# FlexiApp API v2 connector for PHP

A small, dependency-free PHP client for reading content from the
[FlexiApp](https://www.flexiapp.cz) headless CMS through its
[REST API v2](https://api.flexiapp.cz/v2/docs).

- **Read access** to articles, topics, sites, web parts, products and the project.
- **File cache** with a configurable lifetime (24 hours by default). When the
  API is temporarily unavailable, the last known response is served instead
  of an error page.
- **Webhooks**: signature verification ([Standard Webhooks](https://www.standardwebhooks.com))
  and a ready-made endpoint that clears the cache when content changes.
- **Sample data** transport for development and tests without an API key.

> 🇨🇿 [Česká verze níže](#česky)

## Requirements

- PHP 8.2 or newer with the `curl` extension
- A FlexiApp project API key (`fxa_v2_…`)

## Installation

```bash
composer require flexiapp/api-connector-v2
```

## Quick start

```php
use FlexiApp\ApiV2\Cache\FileCache;
use FlexiApp\ApiV2\Client;

$client = new Client(
    'fxa_v2_…',
    new FileCache(__DIR__ . '/var/cache/flexiapp'),
    userAgent: 'example.com',   // optional: identifies your site in API logs
);

// Newest published articles
$page = $client->list('articles', [
    'filter' => 'valid:eq:true',     // drafts, scheduled and expired articles have valid=false
    'sort' => 'publishedAt,desc',
    'size' => 10,
]);

foreach ($page->items as $article) {
    echo $article['name'], "\n";
}

// One article, or null when it does not exist
$article = $client->find('articles', 42);
```

## Reading content

| Method | Returns |
|---|---|
| `list(string $resource, array $query = [])` | one `Page` (`items`, `number`, `size`, `totalElements`, `totalPages`, `hasNext()`) |
| `all(string $resource, array $query = [])` | every item, loading page after page (100 per request) |
| `find(string $resource, int $id)` | object detail, `null` for 404 |
| `project()` | the project the API key belongs to |
| `get(string $path, array $query = [])` | decoded JSON of any GET endpoint |
| `status()` | API health (`ok`), not cached, no API key needed |

Resources: `articles`, `topics`, `sites`, `web-parts`, `products`,
`gallery-images`, `profiles`, `orders`, `webform-data`.

Query parameters are passed as in the [API documentation](https://api.flexiapp.cz/v2/docs).
Arrays become repeated parameters, booleans `true`/`false`, `null` values are skipped:

```php
$client->list('articles', [
    'filter' => ['valid:eq:true', 'name:like:%Teide%'],  // AND
    'sort' => ['validFrom,desc', 'name'],               // or 'random'
    'topic' => 12,                                      // only articles in topic #12
    'q' => 'volcano',                                   // fulltext
    'page' => 0,                                        // 0-based
    'size' => 25,                                       // 1–100
]);
```

Filter operators: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like` (`%` as wildcard).
Dates must be full ISO 8601 with an offset, e.g. `validFrom:lte:2026-07-03T14:30:00+02:00`.

## Errors

Every failure throws `FlexiApp\ApiV2\ApiException`. The API reports problems
as [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) documents:

```php
try {
    $client->list('articles', ['size' => 500]);
} catch (ApiException $e) {
    $e->status;            // 400 (0 for network errors)
    $e->problem['detail']; // "Parameter 'size' must be between 1 and 100."
    $e->isNotFound();      // 404
    $e->isUnauthorized();  // 401 unknown or revoked key, 403 locked key
    $e->isTemporary();     // network error, 408, 429 or 5xx
}
```

## Caching

```php
$cache = new FileCache(__DIR__ . '/var/cache/flexiapp', ttl: 86400);
$client = new Client('fxa_v2_…', $cache);
```

- Fresh responses are served from the cache without any request.
- On a temporary failure (network, 408, 429, 5xx) the client falls back to the
  last stored response, even an expired one. Other errors (400, 401, 403, 404)
  are always thrown.
- After such a failure the client does not call the API again for the rest of
  its life (one PHP request in a typical website): further calls are answered
  from the cache at once, or fail at once when nothing is cached, instead of
  waiting for a timeout each time.
- Entries are keyed by the API root and key, so several projects or
  environments can share one directory.
- `$client->bypassCache()` makes all further calls ask the API (responses are
  still stored), e.g. for previews of unpublished content.
- `$client->requestCount()` tells how many HTTP requests the page needed.
- Pass `null` instead of a cache to disable caching, or implement
  `CacheInterface` for another storage.

To use another HTTP stack (or a test double), implement
`Http\TransportInterface` and pass it as `transport:`. The default
`CurlTransport` takes timeouts (5 s request, 3 s connect) and never follows
redirects, so the API key cannot leak to another address.

The cache directory must be writable by PHP. If it is not, the client keeps
working without a cache and logs a warning. Files are named
`flexiapp-*.json` and `clear()` removes only those, but a dedicated directory
is still the safest choice.

## Webhooks

FlexiApp sends a `cache.flush` webhook when content changes (batched,
usually within 10 minutes), when someone clicks *Flush cache* in the
administration, or when an endpoint is tested. With webhooks in place the
cache can live long and content still shows up quickly.

1. Create an endpoint, e.g. `public/flexiapp/webhook.php`:

   ```php
   use FlexiApp\ApiV2\Cache\FileCache;
   use FlexiApp\ApiV2\Webhook\Webhook;
   use FlexiApp\ApiV2\Webhook\WebhookHandler;

   $handler = new WebhookHandler(
       new Webhook((string) getenv('FLEXIAPP_WEBHOOK_SECRET')),  // "whsec_…"
       new FileCache(__DIR__ . '/../../var/cache/flexiapp'), // the same directory as the Client
   );
   $handler->handleRequest();
   ```

2. Register its `https://` URL in the FlexiApp administration and copy the
   signing secret.
3. Use *Test* in the administration: the endpoint answers `204` and keeps the
   cache (test deliveries need no work).

The handler verifies the signature and the timestamp (±5 minutes), answers
`401` for invalid requests, `405` for anything but POST and `204` otherwise,
including event types it does not know. Clearing the cache is idempotent, so
repeated deliveries are harmless. Extra work can be attached with
`$handler->on('cache.flush', fn (WebhookEvent $event) => …)`; keep it fast,
FlexiApp waits at most 10 seconds.

To verify requests in your own code, use `Webhook::verify($rawBody, $headers)`.
It returns a `WebhookEvent` or throws `WebhookException`.

## Development without an API key

`FixtureTransport` answers from JSON files instead of the API: one file per
resource (`articles.json`, `topics.json`, …) with an array of objects in the
API shape, and `project.json` with one object.

```php
use FlexiApp\ApiV2\Http\FixtureTransport;

$client = new Client('demo', transport: new FixtureTransport(__DIR__ . '/fixtures'));
```

It supports details, paging, `topic`, `topicNot`, all filter operators,
sorting including `random`, and a simple `q`. See `tests/fixtures` for an
example. Do not combine it with a cache, so edits of the files show up at once.

## Testing

```bash
composer test                                   # unit tests
composer analyse                                # PHPStan (level 8)
FLEXIAPP_API_KEY=fxa_v2_… composer test:live    # checks against the real API
```

## Security

Please report vulnerabilities privately, see [SECURITY.md](SECURITY.md).

## License

MIT, see [LICENSE](LICENSE).

---

## Česky

Malý PHP klient bez závislostí pro čtení obsahu z headless CMS
[FlexiApp](https://www.flexiapp.cz) přes [REST API v2](https://api.flexiapp.cz/v2/docs).

**Co umí**

- Čtení článků, témat, stránek, web partů, produktů a údajů projektu.
- Souborovou cache s nastavitelnou délkou (výchozí 24 hodin). Při výpadku API
  vrátí poslední známou odpověď místo chybové stránky.
- Webhooky: ověření podpisu a hotový endpoint, který při změně obsahu smaže cache.
- Ukázková data ze souborů pro vývoj a testy bez API klíče.

**Instalace a použití**

```bash
composer require flexiapp/api-connector-v2
```

```php
$client = new Client('fxa_v2_…', new FileCache(__DIR__ . '/var/cache/flexiapp'));

$clanky = $client->list('articles', ['filter' => 'valid:eq:true', 'sort' => 'publishedAt,desc']);
$clanek = $client->find('articles', 42);   // null, když neexistuje
```

**Webhook pro mazání cache**

1. Vytvořte endpoint (viz [příklad](examples/webhook-endpoint.php)), který
   používá stejnou složku cache jako klient.
2. V administraci FlexiApp zadejte jeho `https://` adresu a zkopírujte
   podpisový klíč (`whsec_…`).
3. Tlačítkem *Otestovat* ověřte, že endpoint odpovídá `204`.

FlexiApp pak cache smaže sám po každé změně obsahu (dávkově, zpravidla do
10 minut) i po ručním *Vyprázdnit cache*. Cache tak může držet dlouho a obsah
se přesto objeví rychle.

Podrobnosti jsou v anglické části výše.
