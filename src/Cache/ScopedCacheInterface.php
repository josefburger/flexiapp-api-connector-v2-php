<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Cache;

/**
 * A cache that can drop the responses of selected API resources only.
 *
 * The scope of an entry is the resource in its API path: the response of
 * `/v2/articles?…` or `/v2/articles/42` belongs to `articles`, `/v2/project`
 * to `project`. The `cache.flush` webhook uses it to clear only what
 * changed (see {@see \FlexiApp\ApiV2\Webhook\WebhookHandler}).
 */
interface ScopedCacheInterface extends CacheInterface
{
    /**
     * Removes the entries of the given resources and returns how many were removed.
     *
     * @param list<string> $scopes resource names, e.g. `['articles', 'topics']`
     */
    public function clearScopes(array $scopes): int;
}
