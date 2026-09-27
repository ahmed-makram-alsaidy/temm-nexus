# @platform/backend-sdk

Official TypeScript client for the Laravel Backend Platform (API v1).
Dependency-free at runtime (uses global `fetch` / `WebSocket`). See
`docs/CLIENT_API_CONTRACT.md` for the wire contract and
`docs/client-sdk/` for guides.

```ts
import { BackendClient } from '@platform/backend-sdk';

const backend = new BackendClient({
  baseUrl: 'https://api.example.com',
  apiKey: 'cp_…', // CLIENT-SAFE key only. Never a server secret in the browser.
});

await backend.auth.login({ email: 'ada@example.com', password: '…' });
const me = await backend.auth.me();
await backend.functions.invoke('hello-platform', { body: { name: me.name } });
await backend.auth.logout();
```

## Token storage

The core never touches `localStorage` directly. Inject a store:

```ts
import { BackendClient, MemoryTokenStore, BrowserTokenStore } from '@platform/backend-sdk';

// Default (SSR-safe, tests, Node):
new BackendClient({ baseUrl, tokenStore: new MemoryTokenStore() });

// Trusted first-party browser origin only (XSS reads localStorage):
new BackendClient({ baseUrl, tokenStore: new BrowserTokenStore() });
```

Next.js: use `MemoryTokenStore` (per-request client) on the server, never
`BrowserTokenStore`, and never expose server secret keys to the bundle
(see `docs/client-sdk/REACT_NEXT.md`).

## Local use (this phase — not published)

```bash
cd packages/backend-sdk-js
npm install
npm run build
npm test
```

Consume via `file:` dependency or workspace alias until the package is published.
