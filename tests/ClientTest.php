<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests;

use FlexiApp\ApiV2\ApiException;
use FlexiApp\ApiV2\Cache\FileCache;
use FlexiApp\ApiV2\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/flexiapp-client-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir . '/*') ?: []);
        @rmdir($this->cacheDir);
    }

    public function testBuildsQueryWithRepeatedParametersAndBooleans(): void
    {
        $query = Client::buildQuery([
            'filter' => ['valid:eq:true', 'name:like:%Teide%'],
            'sort' => 'publishedAt,desc',
            'flag' => false,
            'topic' => null,
            'q' => '',
            'page' => 0,
        ]);

        self::assertSame('filter=valid%3Aeq%3Atrue&filter=name%3Alike%3A%25Teide%25&sort=publishedAt%2Cdesc&flag=false&page=0', $query);
    }

    public function testSendsBearerTokenToHttpsBaseUrl(): void
    {
        $transport = (new FakeTransport())->willReturn(['id' => 1]);
        $client = new Client('fxa_v2_secret', transport: $transport);

        $client->project();

        self::assertSame('https://api.flexiapp.cz/v2/project', $transport->requests[0]['url']);
        self::assertSame('Bearer fxa_v2_secret', $transport->requests[0]['headers']['Authorization']);
    }

    public function testListReturnsPage(): void
    {
        $transport = (new FakeTransport())->willReturn([
            'data' => [['id' => 1], ['id' => 2]],
            'page' => ['number' => 0, 'size' => 2, 'totalElements' => 5, 'totalPages' => 3],
        ]);
        $page = (new Client('key', transport: $transport))->list('articles', ['size' => 2]);

        self::assertSame([1, 2], array_column($page->items, 'id'));
        self::assertSame(5, $page->totalElements);
        self::assertTrue($page->hasNext());
    }

    public function testAllLoadsEveryPage(): void
    {
        $transport = (new FakeTransport())
            ->willReturn(['data' => [['id' => 1]], 'page' => ['number' => 0, 'size' => 100, 'totalElements' => 2, 'totalPages' => 2]])
            ->willReturn(['data' => [['id' => 2]], 'page' => ['number' => 1, 'size' => 100, 'totalElements' => 2, 'totalPages' => 2]]);

        $items = (new Client('key', transport: $transport))->all('articles', ['filter' => 'valid:eq:true', 'page' => 7]);

        self::assertSame([1, 2], array_column($items, 'id'));
        self::assertStringContainsString('page=0&size=100&filter=valid%3Aeq%3Atrue', $transport->requests[0]['url']);
        self::assertStringContainsString('page=1&size=100', $transport->requests[1]['url']);
    }

    public function testFindReturnsNullFor404(): void
    {
        $transport = (new FakeTransport())->willReturn(['status' => 404, 'title' => 'Resource not found'], 404);

        self::assertNull((new Client('key', transport: $transport))->find('articles', 999));
    }

    public function testErrorsCarryProblemDetails(): void
    {
        $transport = (new FakeTransport())->willReturn([
            'type' => 'https://api.flexiapp.cz/errors/invalid-query-parameter',
            'status' => 400,
            'detail' => "Parameter 'size' must be between 1 and 100.",
        ], 400);

        try {
            (new Client('key', transport: $transport))->list('articles', ['size' => 500]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString("'size' must be between", $e->getMessage());
            self::assertSame('https://api.flexiapp.cz/errors/invalid-query-parameter', $e->problem['type'] ?? null);
        }
    }

    public function testMissingApiKeyFailsWithoutRequest(): void
    {
        $transport = new FakeTransport();
        $this->expectException(ApiException::class);

        try {
            (new Client('', transport: $transport))->project();
        } finally {
            self::assertSame([], $transport->requests);
        }
    }

    public function testCachesResponses(): void
    {
        $transport = (new FakeTransport())->willReturn(['id' => 1]);
        $client = new Client('key', new FileCache($this->cacheDir), $transport);

        $first = $client->find('articles', 1);
        $second = $client->find('articles', 1);

        self::assertSame(['id' => 1], $first);
        self::assertSame($first, $second);
        self::assertSame(1, $client->requestCount());
    }

    public function testServesExpiredCacheWhenApiIsDown(): void
    {
        $cache = new FileCache($this->cacheDir, ttl: -1); // everything is expired immediately
        $transport = (new FakeTransport())
            ->willReturn(['id' => 1, 'name' => 'Cached'])
            ->willReturn(['title' => 'Service unavailable'], 503);
        $client = new Client('key', $cache, $transport);

        $client->find('articles', 1);
        self::assertSame('Cached', $client->find('articles', 1)['name'] ?? null);
        self::assertSame(2, $client->requestCount());
    }

    public function testServesExpiredCacheWhenNetworkFails(): void
    {
        $cache = new FileCache($this->cacheDir, ttl: -1);
        $transport = (new FakeTransport())
            ->willReturn(['id' => 1, 'name' => 'Cached'])
            ->willFail(new ApiException('Cannot reach the FlexiApp API: timeout'));
        $client = new Client('key', $cache, $transport);

        $client->find('articles', 1);

        self::assertSame('Cached', $client->find('articles', 1)['name'] ?? null);
    }

    public function testStopsCallingApiAfterOutage(): void
    {
        $cache = new FileCache($this->cacheDir, ttl: -1);
        $warmup = (new FakeTransport())->willReturn(['id' => 1])->willReturn(['id' => 2]);
        $warm = new Client('key', $cache, $warmup);
        $warm->find('articles', 1);
        $warm->find('articles', 2);

        $transport = (new FakeTransport())->willFail(new ApiException('timeout'));
        $client = new Client('key', $cache, $transport);

        self::assertSame(['id' => 1], $client->find('articles', 1));
        self::assertSame(['id' => 2], $client->find('articles', 2)); // from cache, no second timeout
        self::assertCount(1, $transport->requests);

        try {
            $client->find('articles', 3); // nothing cached: fails at once
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('timeout', $e->getMessage());
        }
        self::assertCount(1, $transport->requests);
    }

    public function testCacheIsSeparatedByBaseUrl(): void
    {
        $cache = new FileCache($this->cacheDir);
        (new Client('key', $cache, (new FakeTransport())->willReturn(['env' => 'production'])))->project();

        $staging = new Client('key', $cache, (new FakeTransport())->willReturn(['env' => 'staging']), 'https://staging.example.com');

        self::assertSame(['env' => 'staging'], $staging->project());
    }

    public function testAllStopsOnEmptyPageEvenWithWrongMetadata(): void
    {
        $transport = (new FakeTransport())
            ->willReturn(['data' => [['id' => 1]], 'page' => ['number' => 0, 'size' => 100, 'totalElements' => 999999, 'totalPages' => 999999]])
            ->willReturn(['data' => [], 'page' => ['number' => 0, 'size' => 100, 'totalElements' => 999999, 'totalPages' => 999999]]);

        self::assertSame([1], array_column((new Client('key', transport: $transport))->all('articles'), 'id'));
        self::assertCount(2, $transport->requests);
    }

    public function testUserAgentNamesApplicationAndLibraryVersion(): void
    {
        $transport = (new FakeTransport())->willReturn(['id' => 1])->willReturn(['id' => 1]);

        (new Client('key', transport: $transport))->project();
        (new Client('key', transport: $transport, userAgent: 'example.com'))->project();

        self::assertSame('flexiapp-api-connector-v2-php/' . Client::VERSION, $transport->requests[0]['headers']['User-Agent']);
        self::assertSame('example.com flexiapp-api-connector-v2-php/' . Client::VERSION, $transport->requests[1]['headers']['User-Agent']);
    }

    public function testDoesNotHideClientErrorsBehindCache(): void
    {
        $cache = new FileCache($this->cacheDir, ttl: -1);
        $transport = (new FakeTransport())
            ->willReturn(['id' => 1])
            ->willReturn(['detail' => 'API key is locked.'], 403);
        $client = new Client('key', $cache, $transport);
        $client->project();

        $this->expectException(ApiException::class);
        $client->project();
    }

    public function testBypassCacheAsksApiAgain(): void
    {
        $transport = (new FakeTransport())->willReturn(['v' => 1])->willReturn(['v' => 2]);
        $client = new Client('key', new FileCache($this->cacheDir), $transport);

        $client->project();
        $client->bypassCache();

        self::assertSame(['v' => 2], $client->project());
    }

    public function testStatusNeedsNoApiKey(): void
    {
        $transport = (new FakeTransport())->willReturn(['status' => 'ok']);

        self::assertSame('ok', (new Client('', transport: $transport))->status());
        self::assertArrayNotHasKey('Authorization', $transport->requests[0]['headers']);
    }
}
