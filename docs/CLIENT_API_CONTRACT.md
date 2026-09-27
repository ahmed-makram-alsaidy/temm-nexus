# Canonical Client API Contract — Platform API v1

Audited sources (exact paths in this repo):

- `projects/_template/overlay/routes/api.php` — route surface
- `projects/_template/overlay/bootstrap/app.php` — JSON rendering + proxy trust
- `projects/_template/overlay/app/Providers/AppServiceProvider.php` — `throttle:api` 60/min
- `projects/_template/overlay/app/Http/Controllers/Api/HealthController.php`
- `projects/_template/overlay/app/Services/HealthService.php`
- `projects/_template/overlay/app/Http/Requests/Api/LoginRequest.php`
- `projects/_template/overlay/app/Http/Resources/UserResource.php`
- `apps/owner-console/app/Http/Controllers/FunctionInvokeController.php` — `/f/{project}/{function}`
- `apps/owner-console/app/Services/ControlPlane/ApiKeyService.php` — scopes, `prefix.secret` format
- `apps/owner-console/app/Services/ControlPlane/RealtimeService.php` — channel rules, private-channel auth
- `apps/owner-console/app/Services/ControlPlane/FunctionRunner.php` — invocation envelope
- `docs/STORAGE.md`, `docs/AUTH_MANAGEMENT.md`, `docs/API_MANAGEMENT.md`, `docs/REALTIME.md`

> This document describes what a client may rely on. Anything not listed here
> is NOT part of the contract and must not be depended on by SDKs.

---

## 1. Base URL

- Per project: `https://<api_domain>` (Control Plane → project `api_domain`;
  local template default `APP_URL=https://api.PROJECT.test`).
- All project application routes live under `<baseUrl>/api`.
- Control Plane function proxy (server functions) lives on the **console** host,
  not the project host: `<consoleUrl>/f/{projectSlug}/{functionSlug}`.
- Signed downloads live on the console host: `<consoleUrl>/sdl/{project}/{bucket}/{key}?signature=…&expires=…`.
- Clients MUST treat base URL as configuration (constructor arg / env var),
  never hard-code it. Trailing slash is ignored by SDKs.

## 2. API versioning strategy

- URL versioning. Current: **v1** under `/api/v1`.
- Unversioned probes stay unversioned forever:
  - `GET /api/health` (load-balancer / monitoring probe)
  - `GET /up` (Laravel default)
- Breaking changes ⇒ new prefix (`/api/v2`); v1 is supported for the life of
  the project that ships it. Additive fields are NOT breaking.
- `GET /api/health` response (audited, `HealthService::check()`):

```json
{
  "ok": true,
  "app": "ProjectName",
  "time": "2026-09-16T12:00:00+00:00",
  "database": { "ok": true },
  "redis": { "ok": true }
}
```

HTTP 200 when `ok:true`, 503 otherwise. `database`/`redis` degrade to
`{"ok":false,"error":"unavailable"}` when `APP_DEBUG=false` (no internals leak).

## 3. Authentication

### 3.1 User tokens (Sanctum, per project database)

- Scheme: `Authorization: Bearer <tokenId>|<plainText>` (Sanctum personal
  access token, stored in the **project's own** `personal_access_tokens` table).
- Canonical endpoints (template provides `GET /v1/user`; login/logout are the
  documented per-project pattern — see `routes/api.php` comments — proven live
  by `scripts/prove-demo-auth.sh`: login → me → logout → 401):

| Method | Path | Auth | Body / Notes |
|---|---|---|---|
| `POST` | `/api/v1/auth/register` | none | `{name,email,password,password_confirmation,device_name?}` → 201 `{user,token}` |
| `POST` | `/api/v1/auth/login` | none, `throttle:10,1` recommended | `{email,password,device_name?}` (see `LoginRequest`) → 200 `{user,token}`. Disabled user → **403**. Wrong password → **422**. |
| `POST` | `/api/v1/auth/logout` | Bearer user token | Revokes **current** token → 204/200 |
| `GET` | `/api/v1/user` | Bearer user token | `UserResource` (below). No token → 401 |
| `POST` | `/api/v1/auth/forgot-password` | none, existence-safe | Always 200/202, never reveals whether the email exists |
| `POST` | `/api/v1/auth/reset-password` | signed token from email | `{token,email,password,password_confirmation}` |
| `GET` | `/api/v1/email/verify/{id}/{hash}` | signed URL | Email verification |
| `POST` | `/api/v1/auth/refresh` | Bearer (if project issues expiring tokens) | Optional; Sanctum default tokens do not expire — projects that add expiry MUST offer this |
| `DELETE` | `/api/v1/auth/sessions` | Bearer | Revoke all other sessions; `DELETE /api/v1/auth/sessions/{id}` revokes one |

- `device_name` (string ≤255, optional) labels the token (audited in
  `LoginRequest`: `sometimes|string|max:255`).
- `UserResource` shape (audited — SDK `User` type MUST match exactly):

```json
{
  "id": 1,
  "name": "Ada",
  "email": "ada@example.com",
  "email_verified_at": "2026-09-16T12:00:00.000000Z",
  "created_at": "2026-09-16T12:00:00.000000Z"
}
```

Never contains a password hash. Control Plane user views also never render hashes.

> Envelope nuance (proven live 22.1): when a `JsonResource` is returned
> directly as a route response, Laravel wraps it as `{"data": {…}}`; the same
> resource nested inside an explicit `response()->json([...])` stays bare.
> SDKs accept both shapes for single-object reads (`me`, `get`).

### 3.2 Project API keys (service / function / storage / realtime-private)

- Format (audited `ApiKeyService::create`): `<prefix>.<secret>` where prefix
  is `cp_<8 random>` and secret is 40 random chars. Stored **only as sha256**.
- Presentation (audited `FunctionInvokeController::presentedKey` — SDKs MUST
  implement all three, in this order):
  1. `Authorization: Bearer <prefix.secret>`
  2. `X-API-Key: <prefix.secret>`
  3. `?key=<prefix.secret>` (least preferred; avoid in logs)
- Scopes (audited `ApiKeyService::SCOPES` — exhaustive):
  `read:data`, `write:data`, `storage:read`, `storage:write`, `functions:invoke`.
- Lifecycle: shown **ONCE** at creation (single-use reveal token, 5 min),
  rotatable (old revoked), revocable, optional expiry. `last_used_at` stamped
  on successful validation.
- **CLIENT SAFE vs SERVER ONLY** (binding, see `docs/client-sdk/SECURITY.md`):
  - CLIENT SAFE (may ship in browser / Flutter / desktop builds): keys whose
    scopes are the minimum the screen needs — typically `read:data`,
    `storage:read`, `functions:invoke` on public/low-risk functions.
  - SERVER ONLY (never bundled): keys with `write:data`, `storage:write`, or
    invoke on privileged functions. Server-to-server (PHP SDK) uses these.

### 3.3 What the SDK attaches automatically

- If a user token is stored → `Authorization: Bearer <userToken>` on every
  request except the auth endpoints themselves.
- If only an API key is configured → key presentation (Bearer by default,
  `X-API-Key` opt-in) on storage/functions/realtime-private calls.
- Precedence (high → low): explicit per-call token, explicit per-call API key,
  stored user token, configured API key. In particular an explicit `apiKey`
  wins over a stored user token (proven live 22.1: key-auth functions called
  from a logged-in client). Explicit per-call credentials override stored ones;
  an explicit `Authorization` header is never overwritten.

## 4. Errors — stable error model

Laravel's native shapes are preserved on the wire (no new envelope invented —
the existing API does not use one and would not benefit from one). The SDKs
**normalize** every failure to this stable shape:

```json
{
  "message": "Validation failed",
  "code": "VALIDATION_ERROR",
  "errors": { "email": ["The email field is required."] },
  "request_id": "req_abc123",
  "status": 422
}
```

- `message`: Laravel `message` field, or HTTP reason phrase fallback. Never a
  stack trace when `APP_DEBUG=false` (`HealthService::probe` proves the pattern:
  `'unavailable'` instead of `$e->getMessage()`).
- `code`: stable SDK-side enum derived deterministically (see table). If the
  server ever sends a `code` field, it is passed through; otherwise mapped.
- `errors`: present only on 422 (Laravel `{message, errors}` shape).
- `request_id`: `X-Request-ID` response header when present (functions,
  realtime, SQL runner flows always echo it); otherwise the SDK-generated
  outgoing request ID. Correlates with Control Plane Logs Explorer.
- `status`: HTTP status.

| Status | `code` | Wire shape (observed) | SDK behavior |
|---|---|---|---|
| 400 | `BAD_REQUEST` | `{message}` | throw, no retry |
| 401 | `UNAUTHENTICATED` | `{message}` (e.g. "API key required.", "Invalid API key.", "User token required.") | throw, clear cached user, **do not** retry with same credential |
| 403 | `FORBIDDEN` | `{message}` (e.g. disabled user, unknown auth mode) | throw, no retry |
| 404 | `NOT_FOUND` | `{message}` (project/function/row) | throw, no retry |
| 405 | `METHOD_NOT_ALLOWED` | `{error}` inside function body | throw, no retry |
| 409 | `CONFLICT` | `{message}` (e.g. duplicate email) | throw, no retry |
| 422 | `VALIDATION_ERROR` | `{message, errors:{field:[…]}}` | throw with `errors` map |
| 429 | `RATE_LIMITED` | empty body + `Retry-After` header | throw with `retryAfterMs`; SDKs auto-retry **only** idempotent GETs, once, after `Retry-After` |
| 500 | `SERVER_ERROR` | `{error:"Executor error."}` / `{message}` (never trace when debug off) | throw, no auto-retry |
| 503 | `UNAVAILABLE` | function disabled / no deployed version | throw, no auto-retry |

Network failures (DNS, timeout, abort) surface as `code: NETWORK_ERROR` /
`TIMEOUT` / `ABORTED`, never as HTTP statuses.

## 5. Pagination

Canonical = Laravel paginator. No custom envelope. Clients MUST accept both:

```json
{
  "data": [ … ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" },
  "meta": { "current_page": 1, "from": 1, "last_page": 5, "per_page": 15, "to": 15, "total": 73 }
}
```

- Query: `?page=2&per_page=25` (`per_page` capped server-side; SDK default 15,
  max 100). SDK `list()` returns `{data, meta}` and `listAll()` follows `next`.
- `UserResource`-style single objects are returned bare (no `data` wrap).

## 6. Filtering / Sorting

Convention for project list endpoints (Control Plane user search/sort/paginate
is the reference implementation):

- Filtering: `?filter[field]=value`, e.g. `?filter[status]=active&filter[role]=admin`.
  Exact match unless the endpoint documents otherwise. Unknown filters are ignored.
- Search: `?search=ada` (prefix/substring per endpoint docs).
- Sorting: `?sort=created_at` ascending, `?sort=-created_at` descending.
  Multi-sort: `?sort=name,-created_at`. Unknown keys ignored.
- SDK helper: `qs({filter, search, sort, page, perPage})` builds exactly this.

## 7. File upload

- Application uploads: `POST /api/v1/storage/{bucket}/upload`
  `multipart/form-data` with field `file` (+ optional `prefix`, `visibility`).
  Response `StorageObject`:

```json
{
  "bucket": "avatars",
  "key": "u/1/photo.jpg",
  "size": 41203,
  "mime": "image/jpeg",
  "url": "https://api.example.com/storage/avatars/u/1/photo.jpg",
  "temporary_url": null,
  "etag": "…"
}
```

- Rules (audited `docs/STORAGE.md` + Storage Studio): app code uses
  `Storage` default disk only; public URLs via `Storage::url()`, private via
  `temporaryUrl()`; per-bucket policy (visibility, max size, MIME allowlist)
  enforced server-side — 422 `VALIDATION_ERROR` on violation; large files use
  presigned direct-to-S3 POST (SDK `storage.presignedUpload()`), bypassing PHP.
- Download: `GET /api/v1/storage/{bucket}/{key}` (auth per bucket policy) or
  signed console URL `GET /sdl/{project}/{bucket}/{key}?expires=&signature=`
  (signature is the bearer — SDK `storage.signedUrl()` returns the full URL).
- Confinement: `..` / absolute keys rejected with 400.

## 8. Function invocation

- Route (audited): `{GET,POST,PUT,PATCH,DELETE} /f/{projectSlug}/{functionSlug}`
  on the **console** host, `throttle:120,1`.
- Auth mode per function (`public | key | user | internal`):
  - `public`: no credential.
  - `key`: project API key with `functions:invoke` scope.
  - `user`: project Sanctum user token.
  - `internal`: owner session — never used by third-party SDKs.
- Request: query string + optional JSON or raw body; `Content-Type` forwarded.
- `X-Request-ID`: optional inbound (must match `^[A-Za-z0-9\-_:.]{1,128}$`),
  always echoed outbound. SDK generates one per call (`crypto.randomUUID()` /
  `Uuid().v4()` / `Str::uuid()`) and returns it in `FunctionResponse`.
- Response: `FunctionRunner::invoke` result body passed through with its
  status; errors are `{error: "<stable string>"}` (never traces).

```json
// POST /f/acme/hello-platform  {"name":"Ada"}
{ "greeting": "hello, Ada" }
```

SDK: `backend.functions.invoke('hello-platform', {body, method, query})`
→ `{data, status, requestId, durationMs, version}`.

## 9. Realtime

- Transport: Reverb = Pusher protocol over WebSocket.
  Endpoint per project: `{wsScheme}://{reverb_host}:{reverb_port}/app/{appKey}?protocol=7`
  (`REVERB_HOST/PORT/SCHEME` in project `.env`; Control Plane shows host/port
  + key **state only**).
- Channels: `^[A-Za-z0-9_.:-]{1,160}$` (audited `RealtimeService::validateChannel`,
  cap enforced). `private-` / `presence-` prefixes require the project API key
  (channel auth); anonymous subscribe to them is refused with 401.
- Events: name same regex, payload MUST be a JSON object, capped at 4000 chars.
- SDK surface (all three SDKs identical):

```ts
const sub = await backend.realtime.subscribe({ channel: 'orders', onEvent: (e) => … });
sub.unsubscribe();
await backend.realtime.unsubscribeAll();
```

- Guarantees: no duplicate subscriptions (subscribe to an active channel
  returns the existing handle); exponential-backoff reconnect with jitter;
  private-channel auth failure surfaces as `UNAUTHENTICATED`, never retried silently.

## 10. Rate-limit responses

- Limits (audited): `throttle:api` 60/min per IP; `/api/health` 60/min;
  `/f/…` 120/min; auth login endpoints SHOULD use `throttle:10,1`.
- 429 response: HTTP 429, `Retry-After: <seconds>`, standard
  `X-RateLimit-Limit/Remaining/Reset` when the limiter provides them.
- SDK: surfaces `retryAfterMs`; retries **only** safe idempotent GETs once
  after the advertised delay; never retries POST/PUT/PATCH/DELETE blindly.

## 11. Request IDs

- Header: `X-Request-ID`. Client MAY send; server (functions, realtime events,
  SQL runner) always responds with one and persists it in invocation/event/log rows.
- Format accepted inbound by functions: `^[A-Za-z0-9\-_:.]{1,128}$`; SDKs
  generate UUIDv4 by default and propagate the value through
  `ApiError.requestId` and `FunctionResponse.requestId`.
- Control Plane Logs Explorer + API Studio response panel filter on this ID —
  SDK docs tell users to paste it when asking for help.

## 12. What is deliberately NOT in the contract

- No global `{data, meta}` JSON envelope — Laravel resources are bare, paginators
  are standard. SDKs do not wrap/unwrap beyond this document.
- No server-rendered HTML, no session cookies for third-party clients
  (Sanctum tokens are Bearer, not cookies, on the API surface).
- No direct database access — `ProjectConnections` credentials are owner-only,
  never shipped to SDKs.
- No secrets in URLs except the documented `?key=` fallback and signed
  download URLs (which are bearer by design and expiring).

## 13. Compatibility promise

- `Platform API v1` in this document is what SDK `0.x` targets (see
  `docs/client-sdk/VERSIONING.md`). Additive changes only; any breaking change
  ships as `/api/v2` with a migration note, never as a silent v1 change.
