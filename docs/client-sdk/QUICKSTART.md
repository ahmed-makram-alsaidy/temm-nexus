# Quickstart — connect a new project in under 10 minutes

Prerequisite: project credentials exist (Control Plane → project → **Connect**).
You need: `API_URL`, `PROJECT_SLUG`, one CLIENT-SAFE key (`PUBLIC_KEY`).

## 1. JavaScript / TypeScript (2 min)

```bash
npm install @platform/backend-sdk   # Phase 22: file: link — see docs/client-sdk/DISTRIBUTION.md
```

```ts
import { BackendClient } from '@platform/backend-sdk';

const backend = new BackendClient({
  baseUrl: 'https://api.example.com', // API_URL from Connect
  apiKey: 'PUBLIC_KEY',
  projectSlug: 'acme',
  functionsBaseUrl: 'https://console.example.com',
});

await backend.auth.login({ email: 'ada@example.com', password: '…' });
const me = await backend.auth.me();
console.log(me.email);
await backend.auth.logout();
```

## 2. Flutter (3 min)

```yaml
dependencies:
  backend_sdk:
    path: ../../packages/backend_sdk_dart
```

```dart
final backend = BackendClient(baseUrl: 'https://api.example.com', apiKey: 'PUBLIC_KEY');
await backend.auth.login(email: email, password: password);
final me = await backend.auth.me();
```

## 3. PHP server-to-server (2 min)

```php
$backend = new Platform\BackendSdk\BackendClient(
    'https://api.example.com', getenv('API_SECRET_KEY'), 'acme', 'https://console.example.com');
$res = $backend->functions()->invoke('hello-platform', ['name' => 'Ada']);
```

## 4. Verify (1 min)

- `GET {API_URL}/api/health` → `{"ok":true,…}` (no auth).
- Login → `me` → `logout` → `me` must now throw `UNAUTHENTICATED` (401).
- Every thrown SDK error carries `requestId` — paste it into Control Plane →
  Logs Explorer when asking for help.

Next: `JAVASCRIPT.md` / `REACT_NEXT.md` / `FLUTTER.md` / `PHP.md` for your
platform, `AUTH.md` / `STORAGE.md` / `FUNCTIONS.md` / `REALTIME.md` per
feature, `SECURITY.md` before any store submission or public deploy.
