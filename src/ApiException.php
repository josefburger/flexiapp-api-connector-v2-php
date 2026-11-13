<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2;

use RuntimeException;
use Throwable;

/**
 * Error returned by the FlexiApp API or raised while talking to it.
 *
 * The API reports errors as RFC 9457 problem details (application/problem+json);
 * the decoded document is available in {@see $problem}. Network failures have
 * status 0 and no problem document.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param int                       $status  HTTP status code, 0 for network errors
     * @param array<string, mixed>|null $problem RFC 9457 problem details, if the API sent them
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?array $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** The object does not exist, was deleted or belongs to another project (404). */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** The API key is missing, unknown or revoked (401), or locked (403). */
    public function isUnauthorized(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    /**
     * A temporary failure worth retrying later or covering with cached data:
     * network error, timeout (408), rate limit (429) or server error (5xx).
     */
    public function isTemporary(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}
