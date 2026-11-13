<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Webhook;

use InvalidArgumentException;

/**
 * Verifies webhook requests sent by FlexiApp.
 *
 * FlexiApp follows the Standard Webhooks specification
 * (https://www.standardwebhooks.com): the signature is an HMAC-SHA256 of
 * `{webhook-id}.{webhook-timestamp}.{raw body}` keyed with the base64-decoded
 * part of the `whsec_…` secret. The `webhook-signature` header may contain
 * several space-separated `v1,<base64>` signatures (old and new secret during
 * rotation); any match is accepted. Requests older or newer than the
 * tolerance (5 minutes) are rejected to prevent replays.
 */
final class Webhook
{
    /** Allowed difference between `webhook-timestamp` and the current time, in seconds. */
    public const DEFAULT_TOLERANCE = 300;

    private readonly string $key;

    /**
     * @param string $secret    signing secret from the FlexiApp administration (`whsec_…`)
     * @param int    $tolerance accepted clock difference in seconds
     */
    public function __construct(
        #[\SensitiveParameter] string $secret,
        private readonly int $tolerance = self::DEFAULT_TOLERANCE,
    ) {
        $encoded = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key = base64_decode($encoded, true);
        if ($key === false || $key === '') {
            throw new InvalidArgumentException('Invalid webhook secret, expected "whsec_" followed by base64.');
        }
        $this->key = $key;
    }

    /**
     * Checks the signature and timestamp and decodes the payload.
     *
     * @param string                $payload raw request body, exactly as received
     * @param array<string, string> $headers request headers; names are case-insensitive
     * @param int|null              $now     current Unix time (for tests)
     *
     * @throws WebhookException when the request is not a valid FlexiApp webhook
     */
    public function verify(string $payload, array $headers, ?int $now = null): WebhookEvent
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $id = (string) ($headers['webhook-id'] ?? '');
        $timestamp = (string) ($headers['webhook-timestamp'] ?? '');
        $signatures = (string) ($headers['webhook-signature'] ?? '');

        if ($id === '' || $timestamp === '' || $signatures === '') {
            throw new WebhookException('Missing webhook-id, webhook-timestamp or webhook-signature header.');
        }
        if (!ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > $this->tolerance) {
            throw new WebhookException('Webhook timestamp is missing or outside the allowed tolerance.');
        }

        $expected = substr($this->sign($id, (int) $timestamp, $payload), 3);
        $valid = false;
        foreach (explode(' ', $signatures) as $signature) {
            [$version, $value] = array_pad(explode(',', $signature, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $value)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new WebhookException('Webhook signature does not match.');
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || !is_string($data['type'] ?? null)) {
            throw new WebhookException('Webhook payload is not a valid event.');
        }

        return new WebhookEvent(
            $id,
            $data['type'],
            (string) ($data['timestamp'] ?? ''),
            is_array($data['data'] ?? null) ? $data['data'] : [],
        );
    }

    /**
     * Signature in the `webhook-signature` format (`v1,<base64>`). Handy for
     * tests and for sending test requests to your own endpoint.
     */
    public function sign(string $id, int $timestamp, string $payload): string
    {
        return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $payload, $this->key, true));
    }
}
