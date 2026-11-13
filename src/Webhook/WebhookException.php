<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Webhook;

use RuntimeException;

/** The request is not a valid webhook: missing headers, wrong signature or too old. */
final class WebhookException extends RuntimeException
{
}
