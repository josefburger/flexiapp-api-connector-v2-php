<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests\Http;

use FlexiApp\ApiV2\Client;
use FlexiApp\ApiV2\Http\FixtureTransport;
use PHPUnit\Framework\TestCase;

final class FixtureTransportTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = new Client('any-key', transport: new FixtureTransport(__DIR__ . '/../fixtures', randomSeed: 1));
    }

    public function testFiltersAndSorts(): void
    {
        $page = $this->client->list('articles', ['filter' => 'valid:eq:true', 'sort' => 'publishedAt,desc']);

        self::assertSame([2, 1, 4], array_column($page->items, 'id'));
    }

    public function testComparesDatesAsPointsInTime(): void
    {
        $page = $this->client->list('articles', ['filter' => 'validFrom:gte:2025-02-01T00:00:00+01:00']);

        self::assertSame([1, 2], array_column($page->items, 'id'));
    }

    public function testLikeAndTopics(): void
    {
        self::assertSame([1], array_column($this->client->list('articles', ['filter' => 'name:like:%teide%'])->items, 'id'));
        self::assertSame([2, 4], array_column($this->client->list('articles', ['topic' => 11])->items, 'id'));
        self::assertSame([3, 4], array_column($this->client->list('articles', ['topicNot' => 10])->items, 'id'));
    }

    public function testFulltext(): void
    {
        self::assertSame([2], array_column($this->client->list('articles', ['q' => 'bananas'])->items, 'id'));
    }

    public function testPaginates(): void
    {
        $page = $this->client->list('articles', ['size' => 3, 'page' => 1, 'sort' => 'id']);

        self::assertSame([4], array_column($page->items, 'id'));
        self::assertSame(4, $page->totalElements);
        self::assertSame(2, $page->totalPages);
        self::assertFalse($page->hasNext());
    }

    public function testRandomOrderIsRepeatableWithSeed(): void
    {
        $first = array_column($this->client->list('articles', ['sort' => 'random'])->items, 'id');
        $second = array_column($this->client->list('articles', ['sort' => 'random'])->items, 'id');

        self::assertSame($first, $second);
        self::assertEqualsCanonicalizing([1, 2, 3, 4], $first);
    }

    public function testDetailProjectAndMissingObjects(): void
    {
        self::assertSame('Garachico', $this->client->find('articles', 4)['name'] ?? null);
        self::assertNull($this->client->find('articles', 404));
        self::assertSame('Demo project', $this->client->project()['name']);
        self::assertTrue($this->client->list('products')->isEmpty());
        self::assertSame('ok', $this->client->status());
    }
}
