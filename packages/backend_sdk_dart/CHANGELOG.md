# Changelog — backend_sdk_dart

Format: Keep a Changelog. Versioning: SemVer (see `docs/client-sdk/VERSIONING.md`).
This package is NOT published to pub.dev in Phase 22 (local/private use only).

## [0.1.1] — 2026-09-17

Live-gate hardening (Phase 22.1, mirroring the proven TS SDK):

- Accept Laravel's `{data: …}` JsonResource wrap on single-object reads.
- Credential precedence: explicit token > explicit apiKey > stored token >
  configured apiKey.
- Realtime: swallow protocol-internal frames (`pusher:*`,
  `pusher_internal:*`).

## [0.1.0] — 2026-09-17

- Initial Client Integration Kit release targeting Platform API v1:
  `BackendClient` with `auth`, `users`, `storage`, `functions`, `realtime`, `http`.
- Token abstraction (`MemoryTokenStore`, `SecureTokenStoreAdapter` for
  `flutter_secure_storage`; plaintext prefs explicitly rejected for production).
- Stable `BackendException` (`code` / `status` / `errors` / `requestId` / `retryAfter`).
- `X-Request-ID` generation + propagation, timeouts, Bearer/`X-API-Key` placement.
- Realtime: Pusher-protocol subscribe/unsubscribe/reconnect, duplicate-subscription
  guard, private-channel key requirement.
