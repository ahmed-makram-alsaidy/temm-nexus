# SDK security (Phase 22M checklist)

Applies to every client built on these SDKs. SDK properties are covered by
`packages/backend-sdk-js/tests/security.test.mjs` (5 tests, passing),
`packages/backend-sdk-php/tests/run-tests.php` (credential-leak test, passing),
and the server-side checklist below.

## CLIENT SAFE vs SERVER ONLY (binding)

| | May ship in browser / Flutter / desktop builds | Server only (PHP SDK, automation) |
|---|---|---|
| Key scopes | Minimum the screen needs: `read:data`, `storage:read`, `functions:invoke` (low-risk functions) | `write:data`, `storage:write`, privileged invokes |
| User tokens | Yes (per-user, revocable) | Only transiently, never logged |
| Never | Server secret keys, `API_SECRET_KEY`, `NEXT_PUBLIC_`-prefixed secrets, raw `?key=` URLs in logs | Committing keys to git, echoing keys in error bodies |

The Connect page renders key **prefixes + scopes + status only** — secrets are
shown once on the Keys page (single-use reveal) and never again.

## What the SDKs guarantee

- No secret logging: `redactHeaders()` / `redactHeaders` / `ApiError::redactHeaders()`
  scrub `Authorization` / `X-API-Key`; `ApiError` messages never embed credentials
  or stack traces (tested).
- No `localStorage` in core: pluggable stores; `BrowserTokenStore` throws on the
  server instead of silently dropping tokens.
- No blind retries: only idempotent GETs after the server's `Retry-After`;
  POST/PUT/PATCH/DELETE are never auto-retried (non-idempotent).
- No duplicate realtime subscriptions; private channels require a key.

## Server-side checks (per project, before exposing clients)

- [ ] Invalid/revoked token → 401 with a stable message, no internals.
- [ ] Expired token → 401; client redirects to login (no silent retry).
- [ ] Cross-project key use → 401 (keys are validated per project + prefix).
- [ ] Disabled user login → 403; wrong password → 422 (proven by `prove-demo-auth.sh`).
- [ ] `APP_DEBUG=false`: error bodies carry no traces (`HealthService` pattern).
- [ ] Storage bucket policy enforced (visibility/size/MIME → 422 on violation).
- [ ] Private/presence realtime subscribe without key → refused.
- [ ] `auth` header logging: verify with `redactHeaders` in every log path.
- [ ] `git-secrets` clean: `scripts/security-check.sh` (no committed keys).
