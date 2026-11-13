<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Tests\Webhook;

use FlexiApp\ApiV2\Webhook\Webhook;
use FlexiApp\ApiV2\Webhook\WebhookException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const ID = 'msg_2LxQpR7vN0aKc3TfY8mWbZ1e';
    private const TIME = 1731420192;
    private const PAYLOAD = '{"type":"cache.flush","timestamp":"2026-11-12T14:03:12Z","data":{"projectId":123,"reason":"manual"}}';

    public function testMatchesStandardWebhooksTestVector(): void
    {
        // Reference vector from the Standard Webhooks specification.
        $signature = (new Webhook(self::SECRET))->sign('msg_p5jXN8AQM9LWM0D4loKWxJek', 1614265330, '{"test": 2432232314}');

        self::assertSame('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', $signature);
    }

    public function testVerifiesValidRequest(): void
    {
        $webhook = new Webhook(self::SECRET);
        $event = $webhook->verify(self::PAYLOAD, $this->headers($webhook->sign(self::ID, self::TIME, self::PAYLOAD)), self::TIME + 30);

        self::assertSame(self::ID, $event->id);
        self::assertSame('cache.flush', $event->type);
        self::assertSame(123, $event->data['projectId']);
        self::assertFalse($event->isTest());
    }

    public function testAcceptsAnyOfSeveralSignaturesAndCaseInsensitiveHeaders(): void
    {
        $webhook = new Webhook(self::SECRET);
        $old = 'v1,' . base64_encode(random_bytes(32));
        $headers = array_change_key_case($this->headers($old . ' ' . $webhook->sign(self::ID, self::TIME, self::PAYLOAD)), CASE_UPPER);

        self::assertSame('cache.flush', $webhook->verify(self::PAYLOAD, $headers, self::TIME)->type);
    }

    public function testRejectsModifiedPayload(): void
    {
        $webhook = new Webhook(self::SECRET);
        $headers = $this->headers($webhook->sign(self::ID, self::TIME, self::PAYLOAD));

        $this->expectException(WebhookException::class);
        $webhook->verify(str_replace('manual', 'auto', self::PAYLOAD), $headers, self::TIME);
    }

    public function testRejectsOldTimestamp(): void
    {
        $webhook = new Webhook(self::SECRET);
        $headers = $this->headers($webhook->sign(self::ID, self::TIME, self::PAYLOAD));

        $this->expectException(WebhookException::class);
        $webhook->verify(self::PAYLOAD, $headers, self::TIME + 301);
    }

    public function testRejectsMissingHeaders(): void
    {
        $this->expectException(WebhookException::class);
        (new Webhook(self::SECRET))->verify(self::PAYLOAD, [], self::TIME);
    }

    public function testRejectsInvalidSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Webhook('whsec_###');
    }

    /** @return array<string, string> */
    private function headers(string $signature): array
    {
        return ['webhook-id' => self::ID, 'webhook-timestamp' => (string) self::TIME, 'webhook-signature' => $signature];
    }
}
