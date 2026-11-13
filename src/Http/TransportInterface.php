<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Http;

use FlexiApp\ApiV2\ApiException;

/**
 * Sends HTTP requests for the {@see \FlexiApp\ApiV2\Client}.
 *
 * The default implementation is {@see CurlTransport}; {@see FixtureTransport}
 * serves local sample data. Implement this interface to plug in another HTTP
 * stack or a test double.
 */
interface TransportInterface
{
    /**
     * @param string                $url     absolute URL including the query string
     * @param array<string, string> $headers request headers
     *
     * @throws ApiException with status 0 when no response was received (network error, timeout)
     */
    public function get(string $url, array $headers): Response;
}
