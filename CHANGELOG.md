# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-18

### Added

- `X-Correlation-Id` header: every `Client` generates one random ID and sends
  it with all its requests, so the API statistics can group the requests of
  one page load (requests per page, N+1 detection). Set your own with the
  `correlationId:` constructor option or `setCorrelationId()`, read it with
  `correlationId()`. Invalid values (anything but 1–64 characters of
  `A-Z a-z 0-9 . _ : -`) throw `InvalidArgumentException`.
- Partial cache flush: `cache.flush` webhooks with `data.scopes` (e.g. only
  `articles`) clear just those resources. A topic change also clears the
  documents that embed topics. Without scopes, with an unknown scope or with a
  cache that cannot do it, everything is cleared as before.
- `ScopedCacheInterface` with `clearScopes()`, implemented by `FileCache`.
- `WebhookEvent::scopes()`, `WebhookHandler::scopesToFlush()` and the
  `partialFlush` option of `WebhookHandler`.

### Changed

- `FileCache` names files `flexiapp-<resource>-<hash>.json`. Entries written
  by 1.0 are still read (so the outage fallback survives the upgrade) and are
  removed by the next flush, partial or full.
- `FileCache::clearScopes()` and `clear()` both remove leftover temporary
  files of interrupted writes.

## [1.0.0] - 2026-11-13

### Added

- `Client` for reading the FlexiApp REST API v2: `get()`, `list()`, `all()`,
  `find()`, `project()`, `status()`.
- `FileCache` with a 24-hour default lifetime that keeps serving the last
  response while the API is unavailable.
- `Webhook` signature verification (Standard Webhooks) and a ready-made
  `WebhookHandler` that clears the cache on `cache.flush`.
- `FixtureTransport` for development and tests without an API key.
- After a temporary API failure the client answers from the cache for 30
  seconds (`outageRetryAfter`) instead of waiting for a timeout on every call.

[1.1.0]: https://github.com/josefburger/flexiapp-api-connector-v2-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/josefburger/flexiapp-api-connector-v2-php/releases/tag/v1.0.0
