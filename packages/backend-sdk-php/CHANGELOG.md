# Changelog — platform/backend-sdk (PHP)

Format: Keep a Changelog. Versioning: SemVer (see `docs/client-sdk/VERSIONING.md`).
This package is NOT published to Packagist in Phase 22 (local/private use only).

## [0.1.0] — 2026-09-17

- Initial Client Integration Kit release targeting Platform API v1:
  server-to-server `BackendClient` (API-key auth) with `functions`, `storage`,
  realtime helpers (channel validation + WS URL builder), health probe.
- Stable `ApiError` (`code` / `status` / `errors` / `requestId` / `retryAfterMs`).
- `X-Request-ID` generation + propagation, cURL timeouts, Bearer/`X-API-Key`
  placement, multipart upload, dependency-free test runner.
