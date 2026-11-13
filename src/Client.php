<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2;

use FlexiApp\ApiV2\Cache\CacheInterface;
use FlexiApp\ApiV2\Http\CurlTransport;
use FlexiApp\ApiV2\Http\Response;
use FlexiApp\ApiV2\Http\TransportInterface;

/**
 * Read client for the FlexiApp REST API v2.
 *
 *     $client = new Client('fxa_v2_…', new FileCache(__DIR__ . '/var/cache/flexiapp'));
 *     $page = $client->list('articles', ['filter' => 'valid:eq:true', 'sort' => 'publishedAt,desc']);
 *     $article = $client->find('articles', 42);
 *
 * Query parameters follow the API: `page` (0-based), `size` (1–100), `sort`,
 * `filter`, `q`, `topic`, `topicNot`. Array values are sent as repeated
 * parameters (`filter=a&filter=b`), booleans as `true`/`false`, null and
 * empty values are skipped.
 *
 * With a cache, successful responses are stored and reused while fresh.
 * When the API is temporarily unavailable (network error, 408, 429, 5xx),
 * the last stored response is returned even if it has expired; the
 * exception is thrown only when there is nothing cached. After the first
 * such failure the instance stops calling the API, so a page with several
 * requests does not wait for a timeout on each of them.
 */
final class Client
{
    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_URL = 'https://api.flexiapp.cz';

    /** Largest page size the API accepts. */
    public const MAX_PAGE_SIZE = 100;

    /** Safety limit for {@see all()}: 1000 pages of 100 items. */
    private const MAX_PAGES = 1000;

    private readonly TransportInterface $transport;

    private int $requestCount = 0;

    private bool $bypassCache = false;

    /** The temporary failure that put the API out of use for this instance. */
    private ?ApiException $outage = null;

    /**
     * @param string                  $apiKey    project API key (`fxa_v2_…`)
     * @param CacheInterface|null     $cache     response cache, null to disable caching
     * @param TransportInterface|null $transport HTTP transport, {@see CurlTransport} by default
     * @param string                  $baseUrl   API root without `/v2`
     * @param string                  $userAgent your application, e.g. `example.com`; prepended to the library's own User-Agent
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly ?CacheInterface $cache = null,
        ?TransportInterface $transport = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly string $userAgent = '',
    ) {
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * GET request to any endpoint, e.g. `get('/v2/articles/42')`.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     *
     * @return array<mixed> decoded JSON response
     *
     * @throws ApiException
     */
    public function get(string $path, array $query = []): array
    {
        $url = '/' . ltrim($path, '/');
        $queryString = self::buildQuery($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        // The key contains a hash of the API root and key (never the key
        // itself), so projects and environments can share one cache directory.
        $cacheKey = substr(hash('sha256', rtrim($this->baseUrl, '/') . "\n" . $this->apiKey), 0, 16) . ' ' . $url;
        $cached = $this->cache?->get($cacheKey);
        $stale = $cached !== null && is_array($cached->value) ? $cached->value : null;
        if ($stale !== null && $cached->fresh && !$this->bypassCache) {
            return $stale;
        }

        // The API already failed during this request: do not wait for it again.
        if ($this->outage !== null) {
            return $stale ?? throw $this->outage;
        }

        try {
            $data = $this->request($url);
        } catch (ApiException $e) {
            if (!$e->isTemporary()) {
                throw $e;
            }
            $this->outage = $e;
            if ($stale === null) {
                throw $e;
            }
            error_log('FlexiApp: ' . $e->getMessage() . '; serving cached responses until the end of the request.');

            return $stale;
        }

        $this->cache?->set($cacheKey, $data);

        return $data;
    }

    /**
     * One page of a list endpoint.
     *
     * @param string                                  $resource e.g. `articles`, `topics`, `sites`, `web-parts`, `products`
     * @param array<string, scalar|list<scalar>|null> $query
     *
     * @throws ApiException
     */
    public function list(string $resource, array $query = []): Page
    {
        return Page::fromResponse($this->get('/v2/' . $resource, $query));
    }

    /**
     * All items of a list endpoint, loading page after page (100 items each).
     *
     * @param array<string, scalar|list<scalar>|null> $query `page` and `size` are managed here
     *
     * @return list<array<string, mixed>>
     *
     * @throws ApiException
     */
    public function all(string $resource, array $query = []): array
    {
        $items = [];
        $number = 0;
        do {
            $page = $this->list($resource, ['page' => $number, 'size' => self::MAX_PAGE_SIZE] + $query);
            array_push($items, ...$page->items);
            $number++;
        } while (!$page->isEmpty() && $number < $page->totalPages && $number < self::MAX_PAGES);

        return $items;
    }

    /**
     * Detail of one object, or null when it does not exist (404).
     *
     * @return array<string, mixed>|null
     *
     * @throws ApiException for errors other than 404
     */
    public function find(string $resource, int $id): ?array
    {
        try {
            return $this->get('/v2/' . $resource . '/' . $id);
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * The project the API key belongs to (name, SEO fields, social profiles,
     * content part settings…).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function project(): array
    {
        return $this->get('/v2/project');
    }

    /**
     * Health check of the API (`ok` when running). Never cached, needs no API key.
     *
     * @throws ApiException
     */
    public function status(): string
    {
        $data = $this->decode($this->send('/v2/status', authenticate: false), '/v2/status');

        return (string) ($data['status'] ?? 'unknown');
    }

    /**
     * Skip fresh cache entries and always ask the API (responses are still
     * stored). Useful for previews of unpublished content.
     */
    public function bypassCache(bool $bypass = true): void
    {
        $this->bypassCache = $bypass;
    }

    /** Number of HTTP requests sent by this instance (cache hits are not counted). */
    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Builds a query string the way the API expects it: arrays become
     * repeated parameters without brackets, booleans `true`/`false`, and
     * null or empty values are skipped.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     */
    public static function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                if (is_bool($item)) {
                    $item = $item ? 'true' : 'false';
                }
                $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }

    /** @return array<mixed> */
    private function request(string $url): array
    {
        if ($this->apiKey === '') {
            throw new ApiException('Missing FlexiApp API key.', 401);
        }

        return $this->decode($this->send($url, authenticate: true), $url);
    }

    private function send(string $url, bool $authenticate): Response
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => ltrim($this->userAgent . ' flexiapp-api-connector-v2-php/' . self::VERSION),
        ];
        if ($authenticate) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $this->requestCount++;

        return $this->transport->get(rtrim($this->baseUrl, '/') . $url, $headers);
    }

    /** @return array<mixed> */
    private function decode(Response $response, string $url): array
    {
        $data = json_decode($response->body, true);

        if ($response->status < 200 || $response->status >= 300) {
            $problem = is_array($data) ? $data : null;
            $detail = $problem['detail'] ?? $problem['title'] ?? substr($response->body, 0, 200);
            throw new ApiException("FlexiApp API returned {$response->status} for {$url}: {$detail}", $response->status, $problem);
        }

        if (!is_array($data)) {
            throw new ApiException("FlexiApp API returned invalid JSON for {$url}.", $response->status);
        }

        return $data;
    }
}
