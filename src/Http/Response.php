<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Http;

/** Raw HTTP response as returned by a {@see TransportInterface}. */
final class Response
{
    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
