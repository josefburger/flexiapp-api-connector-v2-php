<?php

declare(strict_types=1);

namespace FlexiApp\ApiV2\Cache;

/**
 * Stores every response as one JSON file in a directory.
 *
 * Freshness is evaluated on read, so expired entries stay available as a
 * fallback for API outages until the cache is cleared (typically by the
 * `cache.flush` webhook). Writes go through a temporary file and an atomic
 * rename, so concurrent requests never read a half-written entry.
 *
 * Files are named `flexiapp-<hash>.json` and {@see clear()} removes only
 * those, so the directory may be shared with other files (a dedicated one
 * is still recommended). If the directory is not writable the cache
 * silently degrades to a no-op (one warning per request goes to the PHP
 * error log).
 */
final class FileCache implements CacheInterface
{
    /** Default lifetime: 24 hours. Webhooks flush the cache whenever content changes. */
    public const DEFAULT_TTL = 86400;

    private const PREFIX = 'flexiapp-';

    private bool $warned = false;

    /**
     * @param string $directory where the JSON files are stored (created when missing)
     * @param int    $ttl       seconds an entry counts as fresh
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
    }

    public function get(string $key): ?CacheItem
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $entry = $raw === false ? null : json_decode($raw, true);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return null;
        }

        $storedAt = (int) ($entry['storedAt'] ?? 0);

        return new CacheItem($entry['value'], $storedAt, time() - $storedAt < $this->ttl);
    }

    public function set(string $key, mixed $value): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            $this->warn("cannot create directory {$this->directory}");

            return;
        }

        $json = json_encode(
            ['storedAt' => time(), 'key' => $key, 'value' => $value],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $target = $this->file($key);
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            $this->warn("cannot write to {$this->directory}");
        }
    }

    public function clear(): int
    {
        $count = 0;
        foreach (glob($this->directory . '/' . self::PREFIX . '*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        // leftovers of interrupted writes
        foreach (glob($this->directory . '/' . self::PREFIX . '*.tmp') ?: [] as $file) {
            if (@filemtime($file) < time() - 60) {
                @unlink($file);
            }
        }

        return $count;
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . self::PREFIX . sha1($key) . '.json';
    }

    private function warn(string $message): void
    {
        if (!$this->warned) {
            $this->warned = true;
            error_log('FlexiApp FileCache: ' . $message . '; responses are not cached.');
        }
    }
}
