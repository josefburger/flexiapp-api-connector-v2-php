<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Http;

use FlexiApp\ApiV2\ApiException;

/**
 * {@see TransportInterface} built on ext-curl.
 *
 * Redirects are not followed: the API never redirects, and following one
 * could send the API key to another address or over plain HTTP.
 */
final class CurlTransport implements TransportInterface
{
    /**
     * @param int $timeout        whole request limit in seconds
     * @param int $connectTimeout connection limit in seconds
     */
    public function __construct(
        private readonly int $timeout = 5,
        private readonly int $connectTimeout = 3,
    ) {
    }

    public function get(string $url, array $headers): Response
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => min($this->connectTimeout, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_ENCODING => '', // accept any compression curl supports
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if (!is_string($body) || $status === 0) {
            throw new ApiException('Cannot reach the FlexiApp API: ' . ($error !== '' ? $error : 'no response'));
        }

        return new Response($status, $body, $responseHeaders);
    }
}
