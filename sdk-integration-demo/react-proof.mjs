/**
 * Phase 22K React proof.
 *
 * The React starter (`examples/react-next-starter`) calls exactly these SDK
 * methods from its components:
 *   login/page.tsx   → auth.login() → auth.me() → push /dashboard
 *   dashboard/page   → getServerClient(cookie).auth.me() (+ health())
 *   UploadButton     → storage.upload()
 *   api/logout/route → auth.logout() + cookie delete
 *
 * Rendering Next.js here would only test Vercel's framework, not the kit —
 * so this proof drives the identical call sequence through the REAL SDK
 * twice: once as the browser singleton would (shared store), once as a
 * per-request server client would (fresh store from the cookie). It asserts
 * login → protected fetch → logout → 401, i.e. logout invalidates state.
 *
 * Run: npm install && node react-proof.mjs
 */
import assert from 'node:assert/strict';
import { BackendClient, MemoryTokenStore, ApiError } from '@platform/backend-sdk';
import { startMockApi } from './mock-server.mjs';

const step = (n) => console.log(`ok - ${n}`);
const api = await startMockApi();
const apiUrl = `http://127.0.0.1:${api.address().port}`;

try {
  // --- browser side: singleton client (lib/backend.ts getBrowserClient) ---
  let browserClient = null;
  const getBrowserClient = () => (browserClient ??= new BackendClient({
    baseUrl: apiUrl, apiKey: 'cp_demo.clientsafekey', tokenStore: new MemoryTokenStore(),
  }));

  await getBrowserClient().auth.login({ email: 'ada@example.com', password: 'password123', device_name: 'web' });
  const me = await getBrowserClient().auth.me();
  assert.equal(me.email, 'ada@example.com');
  step('login page flow: login → me');

  // login mirrors the token into an HttpOnly cookie for SSR (starter README)
  const cookieToken = await getBrowserClient().auth.getToken();
  assert.ok(cookieToken);

  // --- server side: per-request client (lib/backend.ts getServerClient) ---
  const getServerClient = (token) => {
    const store = new MemoryTokenStore();
    const c = new BackendClient({ baseUrl: apiUrl, tokenStore: store });
    if (token) void store.set(token);
    return c;
  };
  const serverMe = await getServerClient(cookieToken).auth.me();
  assert.equal(serverMe.name, 'Demo Ada');
  const health = await getServerClient(cookieToken).health();
  assert.equal(health.ok, true);
  step('dashboard server flow: cookie token → me + health');

  // no cookie → login redirect equivalent (starter calls redirect('/login'))
  const anon = getServerClient(null);
  await assert.rejects(() => anon.auth.me(), (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED');
  step('protected page without token → UNAUTHENTICATED (redirects to /login)');

  // --- logout route: revoke server-side, clear both states ---
  await getBrowserClient().auth.logout();
  assert.equal(await getBrowserClient().auth.getToken(), null);
  step('logout route: browser store cleared');
  await assert.rejects(
    () => getServerClient(cookieToken).auth.me(),
    (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED',
  );
  step('logout route: stale cookie token now 401s (server state invalidated)');

  console.log('\nREACT PROOF PASS: login → SDK → protected data → logout invalidates');
} finally {
  api.close();
}
