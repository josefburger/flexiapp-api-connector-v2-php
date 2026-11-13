<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Cache;

/**
 * Storage for API responses.
 *
 * Expired entries must still be returned by {@see get()} (marked as not
 * fresh): the client falls back to them when the API is unavailable.
 */
interface CacheInterface
{
    /** The stored entry, fresh or expired, or null when there is none. */
    public function get(string $key): ?CacheItem;

    public function set(string $key, mixed $value): void;

    /** Removes all entries and returns how many were removed. */
    public function clear(): int;
}
