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
 * Files are named `flexiapp-<scope>-<hash>.json`, where the scope is the API
 * resource of the response (`articles`, `topics`, `project`…), so
 * {@see clearScopes()} can drop one resource and keep the rest. {@see clear()}
 * removes only `flexiapp-*` files, so the directory may be shared with other
 * files (a dedicated one is still recommended). If the directory is not
 * writable the cache silently degrades to a no-op (one warning per request
 * goes to the PHP error log).
 */
final class FileCache implements ScopedCacheInterface
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
        $item = $this->read($file);
        if ($item !== null) {
            return $item;
        }

        // 1.0 stored files without the scope in the name. Read them, so an
        // upgrade keeps the warm cache and its outage fallback, and move them
        // under the new name, so the next read finds them at once and
        // clearScopes() knows their resource. On a miss this costs one extra
        // file check, next to the API request that follows it anyway.
        $legacy = $this->legacyFile($key);
        $item = $this->read($legacy);
        if ($item !== null) {
            @rename($legacy, $file); // atomic; a concurrent request may have moved it already
        }

        return $item;
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
        $count = $this->delete(self::PREFIX . '*.json');
        $this->deleteStaleTemporaryFiles();

        return $count;
    }

    /**
     * Also removes entries whose resource is unknown (keys without `/v2/…`
     * and files written by 1.0): they may belong to any of the scopes.
     */
    public function clearScopes(array $scopes): int
    {
        $hash = str_repeat('[0-9a-f]', 40); // exact, so `web` never matches `web-parts`
        $count = $this->delete(self::PREFIX . 'other-' . $hash . '.json') + $this->delete(self::PREFIX . $hash . '.json');
        foreach (array_unique($scopes) as $scope) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', $scope)) { // a resource name; nothing else reaches glob()
                $count += $this->delete(self::PREFIX . $scope . '-' . $hash . '.json');
            }
        }
        $this->deleteStaleTemporaryFiles();

        return $count;
    }

    /**
     * The API resource a cache key belongs to: the first path segment after
     * `/v2/` (`articles` for `… /v2/articles/42?…`), or `other`.
     */
    public static function scopeOf(string $key): string
    {
        return preg_match('#/v2/([a-z][a-z0-9-]*)#', $key, $m) ? $m[1] : 'other';
    }

    private function read(string $file): ?CacheItem
    {
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

    /** Deletes the files matching a glob pattern and returns how many were deleted. */
    private function delete(string $pattern): int
    {
        $count = 0;
        foreach (glob($this->directory . '/' . $pattern) ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /** Leftovers of interrupted writes (older than a minute, so running writes are safe). */
    private function deleteStaleTemporaryFiles(): void
    {
        foreach (glob($this->directory . '/' . self::PREFIX . '*.tmp') ?: [] as $file) {
            if (@filemtime($file) < time() - 60) {
                @unlink($file);
            }
        }
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . self::PREFIX . self::scopeOf($key) . '-' . sha1($key) . '.json';
    }

    private function legacyFile(string $key): string
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
