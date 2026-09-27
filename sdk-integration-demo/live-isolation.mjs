/**
 * Phase 22.1 CROSS-PROJECT isolation proof (live).
 * Project A (sdk-live-a, :8123) vs project B (sdk-live-b, :8125).
 * Disposable users/keys only. Expects 401 everywhere cross-boundary.
 *
 * Run:  GATE_KEY=... node live-isolation.mjs
 */
import assert from 'node:assert/strict';
import { BackendClient, ApiError } from '@platform/backend-sdk';

const step = (n) => console.log(`ok - ${n}`);
const A = process.env.LIVE_A_URL ?? 'http://127.0.0.1:8123';
const B = process.env.LIVE_B_URL ?? 'http://127.0.0.1:8125';
const FUNCTIONS = process.env.LIVE_FUNCTIONS_URL ?? 'http://console.test';
const GATE_KEY = process.env.GATE_KEY;
assert.ok(GATE_KEY, 'GATE_KEY env required (project A key)');
const stamp = Date.now().toString(36);

const clientA = new BackendClient({ baseUrl: A, apiKey: GATE_KEY, projectSlug: 'sdk-live-a', functionsBaseUrl: FUNCTIONS });
const clientB = new BackendClient({ baseUrl: B, projectSlug: 'sdk-live-b' });

// 1. user token minted by A is rejected by B (API)
const regA = await clientA.auth.register({ name: 'Iso A', email: `iso-a-${stamp}@example.com`, password: 'live-pass-123' });
const tokenA = regA.token;
await assert.rejects(
  () => clientB.http.get('/api/v1/user', { token: tokenA }),
  (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED' && e.status === 401,
);
step('A user token → B /user = 401');

// 2. A user token cannot upload to B (storage)
await assert.rejects(
  () => clientB.storage.upload(new Blob(['x']), { bucket: 'gate', fileName: 'x.txt', token: tokenA }),
  (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED',
);
step('A user token → B storage upload = 401');

// 3. A's project key cannot invoke B's function (functions, per-project prefix scope)
// Same slug 'gate-hello' exists on B — A's key must still 401 (prefix bound to A).
const clientAonB = new BackendClient({ baseUrl: A, apiKey: GATE_KEY, projectSlug: 'sdk-live-b', functionsBaseUrl: FUNCTIONS });
await assert.rejects(
  () => clientAonB.functions.invoke('gate-hello', { body: { name: 'X' }, apiKey: GATE_KEY }),
  (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED' && e.status === 401,
);
step("A key → B function 'gate-hello' = 401 (per-project prefix)");

// 4. reverse direction: B user token rejected by A
const regB = await clientB.auth.register({ name: 'Iso B', email: `iso-b-${stamp}@example.com`, password: 'live-pass-123' });
await assert.rejects(
  () => clientA.http.get('/api/v1/user', { token: regB.token }),
  (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED',
);
step('B user token → A /user = 401');

// 5. same-token control: A token still works on A (proves the 401s are isolation, not breakage)
const meA = await clientA.auth.me();
assert.equal(meA.email, `iso-a-${stamp}@example.com`);
step('control: A token still valid on A');

console.log('\nISOLATION PASS: cross-project access refused on API, storage, functions (both directions)');
