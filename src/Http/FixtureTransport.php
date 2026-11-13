<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Http;

use FlexiApp\ApiV2\ApiException;
use Random\Engine\Mt19937;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Serves local JSON files instead of the API, for development without an
 * API key, demos and tests.
 *
 * The directory contains one file per resource with an array of objects in
 * the shape the API returns, e.g. `articles.json`, `topics.json`,
 * `sites.json`, plus `project.json` with a single object. A missing file
 * behaves like an empty list.
 *
 * Supported: object detail, `page`, `size`, `topic`, `topicNot`,
 * `filter=attr:op:value` (eq, neq, gt, gte, lt, lte, like with `%`
 * wildcards), `sort=attr[,asc|desc]` (repeatable) and `sort=random`, and a
 * simple substring match for `q` on name, perex and content. Behaviour follows
 * the API documentation; it is an approximation, not a copy of the server.
 */
final class FixtureTransport implements TransportInterface
{
    /**
     * @param string   $directory  folder with the JSON files
     * @param int|null $randomSeed seed for `sort=random`, null for a different order every time
     */
    public function __construct(
        private readonly string $directory,
        private readonly ?int $randomSeed = null,
    ) {
    }

    public function get(string $url, array $headers): Response
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = self::parseQuery((string) parse_url($url, PHP_URL_QUERY));

        if ($path === '/v2/status') {
            return self::json(200, ['status' => 'ok']);
        }
        if ($path === '/v2/project') {
            $project = $this->load('project');

            return $project === null ? self::notFound($path) : self::json(200, $project);
        }
        if (!preg_match('#^/v2/([a-z-]+)(?:/(\d+))?$#', $path, $m)) {
            return self::notFound($path);
        }

        // only objects count; anything else in the file is ignored
        $items = array_values(array_filter($this->load($m[1]) ?? [], 'is_array'));

        if (isset($m[2])) {
            foreach ($items as $item) {
                if ((string) ($item['id'] ?? '') === $m[2]) {
                    return self::json(200, $item);
                }
            }

            return self::notFound($path);
        }

        return self::json(200, $this->page($items, $query));
    }

    /**
     * @param list<array<string, mixed>>  $items
     * @param array<string, list<string>> $query
     *
     * @return array<string, mixed>
     */
    private function page(array $items, array $query): array
    {
        $topicIds = static fn (array $item): array => array_map('intval', array_column($item['topics'] ?? [], 'id'));
        foreach ($query['topic'] ?? [] as $topic) {
            $items = array_filter($items, static fn (array $i): bool => in_array((int) $topic, $topicIds($i), true));
        }
        foreach ($query['topicNot'] ?? [] as $topic) {
            $items = array_filter($items, static fn (array $i): bool => !in_array((int) $topic, $topicIds($i), true));
        }

        foreach ($query['filter'] ?? [] as $filter) {
            [$attr, $op, $value] = array_pad(explode(':', $filter, 3), 3, '');
            $items = array_filter($items, static fn (array $i): bool => self::matches($i[$attr] ?? null, $op, $value));
        }

        foreach ($query['q'] ?? [] as $q) {
            $items = array_filter($items, static function (array $i) use ($q): bool {
                $text = ($i['name'] ?? '') . ' ' . ($i['perex'] ?? '') . ' ' . strip_tags((string) ($i['content'] ?? ''));

                return (bool) preg_match('/' . preg_quote($q, '/') . '/iu', $text);
            });
        }

        $items = array_values($items);
        $sorts = $query['sort'] ?? [];
        if (in_array('random', $sorts, true)) {
            $engine = $this->randomSeed === null ? new Secure() : new Mt19937($this->randomSeed);
            $items = (new Randomizer($engine))->shuffleArray($items);
        } elseif ($sorts !== []) {
            usort($items, static function (array $a, array $b) use ($sorts): int {
                foreach ($sorts as $sort) {
                    [$attr, $direction] = array_pad(explode(',', $sort, 2), 2, 'asc');
                    $result = self::compare($a[$attr] ?? null, $b[$attr] ?? null);
                    if ($result !== 0) {
                        return strtolower($direction) === 'desc' ? -$result : $result;
                    }
                }

                return 0;
            });
        }

        $size = max(1, min(100, (int) ($query['size'][0] ?? 25)));
        $number = max(0, (int) ($query['page'][0] ?? 0));
        $total = count($items);

        return [
            'data' => array_slice($items, $number * $size, $size),
            'page' => [
                'number' => $number,
                'size' => $size,
                'totalElements' => $total,
                'totalPages' => (int) ceil($total / $size),
            ],
        ];
    }

    private static function matches(mixed $actual, string $op, string $value): bool
    {
        if (is_bool($actual)) {
            $actual = $actual ? 'true' : 'false';
        }
        if ($op === 'like') {
            $pattern = '/^' . str_replace('%', '.*', preg_quote($value, '/')) . '$/iu';

            return (bool) preg_match($pattern, (string) $actual);
        }
        $cmp = self::compare($actual, $value);

        return match ($op) {
            'eq' => $cmp === 0,
            'neq' => $cmp !== 0,
            'gt' => $actual !== null && $cmp > 0,
            'gte' => $actual !== null && $cmp >= 0,
            'lt' => $actual !== null && $cmp < 0,
            'lte' => $actual !== null && $cmp <= 0,
            default => true,
        };
    }

    /** Numbers numerically, ISO dates as points in time, everything else as text. */
    private static function compare(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }
        $timeA = is_string($a) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $a) ? strtotime($a) : false;
        $timeB = is_string($b) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $b) ? strtotime($b) : false;
        if ($timeA !== false && $timeB !== false) {
            return $timeA <=> $timeB;
        }

        return strcmp((string) $a, (string) $b);
    }

    /** @return array<string, list<string>> repeated parameters collected into lists */
    private static function parseQuery(string $queryString): array
    {
        $query = [];
        foreach ($queryString === '' ? [] : explode('&', $queryString) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($name)][] = rawurldecode($value);
        }

        return $query;
    }

    /** @return array<mixed>|null null when the file does not exist */
    private function load(string $name): ?array
    {
        $file = $this->directory . '/' . $name . '.json';
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new ApiException("Fixture file {$file} is not valid JSON.", 500);
        }

        return $data;
    }

    /** @param array<mixed> $data */
    private static function json(int $status, array $data): Response
    {
        return new Response($status, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['content-type' => 'application/json']);
    }

    private static function notFound(string $path): Response
    {
        return self::json(404, [
            'type' => 'https://api.flexiapp.cz/errors/not-found',
            'title' => 'Resource not found',
            'status' => 404,
            'detail' => "No fixture for {$path}.",
            'instance' => $path,
        ]);
    }
}
