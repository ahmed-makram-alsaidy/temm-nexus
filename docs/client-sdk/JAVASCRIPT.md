# JavaScript / TypeScript SDK

Package: `packages/backend-sdk-js` (`@platform/backend-sdk` 0.1.0, private).
Contract: `docs/CLIENT_API_CONTRACT.md` (Platform API v1). Zero runtime deps.

## Install (local phase)

```bash
cd packages/backend-sdk-js && npm install && npm run build && npm test
```

Consume via `"@platform/backend-sdk": "file:../../packages/backend-sdk-js"`.

## Client

```ts
import { BackendClient, MemoryTokenStore, BrowserTokenStore, ApiError } from '@platform/backend-sdk';

const backend = new BackendClient({
  baseUrl: 'https://api.example.com',
  apiKey: 'cp_…',              // CLIENT-SAFE only in browsers
  apiKeyPlacement: 'bearer',   // or 'header' → X-API-Key
  tokenStore: new MemoryTokenStore(), // default; SSR-safe
  timeoutMs: 15000,
  fetchFn: fetch,              // inject for tests/workers
  functionsBaseUrl: 'https://console.example.com',
  projectSlug: 'acme',
  realtime: { wsUrl: 'ws://reverb:8080/app/KEY?protocol=7' },
});
```

## Calls

```ts
await backend.health();
await backend.auth.register({ name, email, password });
await backend.auth.login({ email, password, device_name: 'web' });
await backend.auth.me();
await backend.auth.forgotPassword(email);
await backend.auth.logout();            // revokes server-side, clears store
await backend.users.list({ page: 1, perPage: 25, search: 'ada', sort: '-created_at', filter: { status: 'active' } });
await backend.storage.upload(file, { bucket: 'avatars', fileName: 'p.jpg' });
await backend.storage.signedUrl('invoices', '2026/09/inv-1.pdf');
await backend.functions.invoke('hello-platform', { body: { name: 'Ada' } });
const sub = await backend.realtime.subscribe({ channel: 'orders', onEvent: (e) => console.log(e.event, e.data) });
await sub.unsubscribe();
```

Typed generics: `backend.http.get<User>(…)` / `backend.functions.invoke<MyShape>(…)` /
`qs()` for list params. `ApiError`: `code / status / errors (422) / requestId / retryAfterMs (429)`.
`X-Request-ID` is generated per call and honored when the server echoes one.

## Token storage

Core never touches `localStorage`. `MemoryTokenStore` (default, SSR/tests) or
`BrowserTokenStore` (trusted first-party origins only — XSS reads localStorage;
throws loudly when used on the server). Never persist SERVER-ONLY secret keys
in either — they stay on the server (see `SECURITY.md`).
