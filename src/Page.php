<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2;

/**
 * One page of a list endpoint (`{"data": [...], "page": {...}}`).
 *
 * Page numbers are 0-based, exactly as in the API.
 */
final class Page
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $number,
        public readonly int $size,
        public readonly int $totalElements,
        public readonly int $totalPages,
    ) {
    }

    /** @param array<string, mixed> $response decoded list response */
    public static function fromResponse(array $response): self
    {
        $meta = is_array($response['page'] ?? null) ? $response['page'] : [];
        $items = is_array($response['data'] ?? null) ? array_values($response['data']) : [];

        return new self(
            $items,
            (int) ($meta['number'] ?? 0),
            (int) ($meta['size'] ?? count($items)),
            (int) ($meta['totalElements'] ?? count($items)),
            (int) ($meta['totalPages'] ?? ($items === [] ? 0 : 1)),
        );
    }

    public function hasNext(): bool
    {
        return $this->number + 1 < $this->totalPages;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
