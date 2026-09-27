/**
 * Phase 22.1 LIVE React proof — the exact SDK call sequence the Next.js
 * starter makes (login/page.tsx → dashboard/page.tsx → UploadButton →
 * api/logout/route.ts), executed against the REAL live Laravel project.
 * No mocks. Disposable data only.
 *
 * Run:  GATE_KEY=... node live-react-proof.mjs
 */
import assert from 'node:assert/strict';
import { BackendClient, MemoryTokenStore, ApiError } from '@platform/backend-sdk';

const step = (n) => console.log(`ok - ${n}`);
const API = process.env.LIVE_API_URL ?? 'http://127.0.0.1:8123';
const stamp = Date.now().toString(36);
const EMAIL = `react-live-${stamp}@example.com`;
const PASSWORD = 'live-pass-123';

// lib/backend.ts getBrowserClient(): singleton + browser store
let browserClient = null;
const getBrowserClient = () => (browserClient ??= new BackendClient({
  baseUrl: API, tokenStore: new MemoryTokenStore(),
}));
// lib/backend.ts getServerClient(): fresh client per request from the cookie
const getServerClient = (token) => {
  const store = new MemoryTokenStore();
  const c = new BackendClient({ baseUrl: API, tokenStore: store });
  if (token) void store.set(token);
  return c;
};

// login/page.tsx: register (first visit) → login → me → push /dashboard
await getBrowserClient().auth.register({ name: 'React Live', email: EMAIL, password: PASSWORD });
await getBrowserClient().auth.setToken(null);
await getBrowserClient().auth.login({ email: EMAIL, password: PASSWORD, device_name: 'web' });
const me = await getBrowserClient().auth.me();
assert.equal(me.email, EMAIL);
step('login page flow vs live Laravel: register → login → me');
const cookieToken = await getBrowserClient().auth.getToken();
assert.ok(cookieToken);

// dashboard/page.tsx (Server Component): cookie → me + health
const serverMe = await getServerClient(cookieToken).auth.me();
assert.equal(serverMe.name, 'React Live');
assert.equal((await getServerClient(cookieToken).health()).ok, true);
step('dashboard server flow vs live Laravel: cookie → me + health');

// UploadButton.tsx: storage.upload vs live Laravel
const obj = await getBrowserClient().storage.upload(
  new Blob(['react-live-bytes'], { type: 'text/plain' }),
  { bucket: 'gate', fileName: `react-${stamp}.txt` },
);
assert.equal(obj.bucket, 'gate');
step('UploadButton flow vs live Laravel: upload ok');

// api/logout/route.ts: revoke server-side, clear cookie
await getBrowserClient().auth.logout();
assert.equal(await getBrowserClient().auth.getToken(), null);
await assert.rejects(
  () => getServerClient(cookieToken).auth.me(),
  (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED',
);
step('logout route vs live Laravel: browser cleared + stale cookie 401s');

console.log('\nLIVE REACT PROOF PASS: login → SDK → Laravel → data → logout clears state');
