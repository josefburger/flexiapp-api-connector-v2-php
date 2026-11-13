# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

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

[1.0.0]: https://github.com/josefburger/flexiapp-api-connector-v2-php/releases/tag/v1.0.0
