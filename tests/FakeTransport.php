<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests;

use FlexiApp\ApiV2\ApiException;
use FlexiApp\ApiV2\Http\Response;
use FlexiApp\ApiV2\Http\TransportInterface;

/** Returns queued responses and records the requests. */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $requests = [];

    /** @var list<Response|ApiException> */
    private array $queue = [];

    /** @param array<mixed> $data */
    public function willReturn(array $data, int $status = 200): self
    {
        $this->queue[] = new Response($status, (string) json_encode($data));

        return $this;
    }

    public function willFail(ApiException $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function get(string $url, array $headers): Response
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        $next = array_shift($this->queue) ?? throw new \LogicException("Unexpected request to {$url}");
        if ($next instanceof ApiException) {
            throw $next;
        }

        return $next;
    }
}
