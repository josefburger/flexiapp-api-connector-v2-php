<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests\Integration;

use FlexiApp\ApiV2\ApiException;
use FlexiApp\ApiV2\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checks documented API behaviour against the real server. Needs a project
 * with at least a few valid articles:
 *
 *     FLEXIAPP_API_KEY=fxa_v2_… composer test:live
 */
#[Group('live')]
final class LiveApiTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $key = (string) getenv('FLEXIAPP_API_KEY');
        if ($key === '') {
            self::markTestSkipped('Set FLEXIAPP_API_KEY to run tests against the live API.');
        }
        $this->client = new Client($key);
    }

    public function testStatus(): void
    {
        self::assertSame('ok', $this->client->status());
    }

    public function testProject(): void
    {
        self::assertArrayHasKey('name', $this->client->project());
    }

    public function testSingleSortWithDirection(): void
    {
        $items = $this->client->list('articles', ['sort' => 'validFrom,desc', 'size' => 10])->items;
        $dates = array_map(static fn (array $a): int => (int) strtotime((string) ($a['validFrom'] ?? '')), $items);
        $sorted = $dates;
        rsort($sorted);

        self::assertSame($sorted, $dates);
    }

    public function testSortByPublishedAt(): void
    {
        $items = $this->client->list('articles', ['filter' => 'valid:eq:true', 'sort' => 'publishedAt,desc', 'size' => 10])->items;
        $dates = array_map(static fn (array $a): int => (int) strtotime((string) $a['publishedAt']), $items);
        $sorted = $dates;
        rsort($sorted);

        self::assertSame($sorted, $dates);
    }

    public function testRandomSort(): void
    {
        $page = $this->client->list('articles', ['sort' => 'random', 'size' => 5]);

        self::assertNotEmpty($page->items);
    }

    public function testValidFilterExcludesInvalidArticles(): void
    {
        foreach ($this->client->all('articles', ['filter' => 'valid:eq:true']) as $article) {
            self::assertTrue($article['valid']);
        }
    }

    public function testFulltextFindsArticleByWordFromItsName(): void
    {
        $article = $this->client->list('articles', ['filter' => 'valid:eq:true', 'size' => 1])->items[0] ?? null;
        if ($article === null) {
            self::markTestSkipped('The project has no valid article.');
        }
        $word = (string) array_reduce(
            preg_split('/\W+/u', (string) $article['name']) ?: [],
            static fn (string $longest, string $w): string => mb_strlen($w) > mb_strlen($longest) ? $w : $longest,
            '',
        );

        $ids = array_column($this->client->all('articles', ['q' => $word]), 'id');

        self::assertContains($article['id'], $ids, "Fulltext q={$word} did not find article #{$article['id']}.");
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        self::assertNull($this->client->find('articles', 2_000_000_000));
    }

    public function testInvalidQueryIsReportedAsProblem(): void
    {
        try {
            $this->client->list('articles', ['size' => 101]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertSame(400, $e->problem['status'] ?? null);
        }
    }
}
