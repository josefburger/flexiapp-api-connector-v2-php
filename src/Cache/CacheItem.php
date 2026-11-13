<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Cache;

/** A cached value together with its freshness. */
final class CacheItem
{
    public function __construct(
        public readonly mixed $value,
        public readonly int $storedAt,
        public readonly bool $fresh,
    ) {
    }
}
