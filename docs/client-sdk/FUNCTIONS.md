# Functions

Runtime: versioned bounded executors (`static` / `db_lookup` / `transform`) —
no arbitrary code execution. Every invocation is logged (redacted, capped).
Contract: `docs/CLIENT_API_CONTRACT.md` §8.

```ts
const res = await backend.functions.invoke('hello-platform', {
  method: 'POST',            // GET | POST | PUT | PATCH | DELETE (per-function allowlist)
  query: { page: 1 },
  body: { name: 'Ada' },
});
// { data, status, requestId, durationMs, version }
```

```dart
final res = await backend.functions.invoke('hello-platform', body: {'name': 'Ada'});
```

```php
$res = $backend->functions()->invoke('hello-platform', ['name' => 'Ada'], ['method' => 'POST']);
```

```bash
curl -s -X POST {FUNCTIONS_BASE_URL}/f/{slug}/hello-platform \
  -H 'Authorization: Bearer PUBLIC_KEY' -H 'X-Request-ID: demo-1' \
  -H 'Content-Type: application/json' -d '{"name":"Ada"}'
```

Auth mode is per function: `public` (no credential) | `key` (project key,
`functions:invoke` scope, via `Authorization: Bearer` / `X-API-Key` / `?key=`)
| `user` (project Sanctum token) | `internal` (owner session — SDKs never use).
`X-Request-ID` may be sent (charset `^[A-Za-z0-9\-_:.]{1,128}$`) and is always
echoed — it lands in `res.requestId` / `ApiError.requestId`. Disabled function
→ 503, no deployed version → 503, wrong method → 405, rate overrun → 429.
