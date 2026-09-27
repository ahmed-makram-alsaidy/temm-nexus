# Changelog — @platform/backend-sdk

Format: Keep a Changelog. Versioning: SemVer (see `docs/client-sdk/VERSIONING.md`).
This package is NOT published to npm in Phase 22 (local/private use only).

## [0.1.1] — 2026-09-17

Live-gate hardening (Phase 22.1, proven against real Laravel + Reverb):

- Accept Laravel's `{data: …}` JsonResource wrap on single-object reads
  (`auth.me/register/login/refresh`, `users.get`).
- Credential precedence: explicit token > explicit apiKey > stored token >
  configured apiKey (key-mode functions callable from logged-in clients).
- Realtime: swallow protocol-internal frames (`pusher:*`,
  `pusher_internal:*` such as `subscription_succeeded`).

## [0.1.0] — 2026-09-17

- Initial Client Integration Kit release targeting Platform API v1:
  `BackendClient` with `auth`, `users`, `storage`, `functions`, `realtime`, `http`.
- Pluggable token storage (`MemoryTokenStore`, `BrowserTokenStore`).
- Stable `ApiError` (`code` / `status` / `errors` / `requestId` / `retryAfterMs`).
- `X-Request-ID` generation + propagation, timeout + abort, Bearer/`X-API-Key` placement.
- Realtime: Pusher-protocol subscribe/unsubscribe/reconnect, duplicate-subscription guard,
  private-channel key requirement.
