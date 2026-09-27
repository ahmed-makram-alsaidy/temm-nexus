/**
 * Phase 22.1 LIVE TypeScript SDK E2E — against the REAL booted Laravel project.
 * No mocks. Disposable data only. Exits non-zero on any failure.
 *
 * Env:
 *   LIVE_API_URL      default http://127.0.0.1:8123   (sdk-live-a artisan serve)
 *   LIVE_FUNCTIONS_URL default http://console.test     (control-plane /f surface)
 *   LIVE_WS_URL       default ws://127.0.0.1:8124/app/<REVERB_APP_KEY>?protocol=7
 *                     (key auto-read from projects/sdk-live-a/.env when unset)
 *   GATE_KEY          disposable project API key (functions:invoke) — required
 *
 * Run:  GATE_KEY=... node live-e2e.mjs
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BackendClient, ApiError } from '@platform/backend-sdk';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const step = (n) => console.log(`ok - ${n}`);

const API = process.env.LIVE_API_URL ?? 'http://127.0.0.1:8123';
const FUNCTIONS = process.env.LIVE_FUNCTIONS_URL ?? 'http://console.test';
const GATE_KEY = process.env.GATE_KEY;
assert.ok(GATE_KEY, 'GATE_KEY env required (disposable functions:invoke key)');

function projectEnv(name) {
  const env = fs.readFileSync(path.join(HERE, '..', 'projects', 'sdk-live-a', '.env'), 'utf8');
  return env.match(new RegExp(`^${name}=(.*)$`, 'm'))?.[1]?.trim().replace(/^"|"$/g, '') ?? null;
}

const WS = process.env.LIVE_WS_URL
  ?? `ws://127.0.0.1:8124/app/${projectEnv('REVERB_APP_KEY')}?protocol=7`;

const stamp = Date.now().toString(36);
const EMAIL = `live-${stamp}@example.com`;

const backend = new BackendClient({
  baseUrl: API,
  apiKey: GATE_KEY,
  projectSlug: 'sdk-live-a',
  functionsBaseUrl: FUNCTIONS,
  realtime: { wsUrl: WS },
});

// 1. health (no auth)
const health = await backend.health();
assert.equal(health.ok, true);
assert.equal(health.database?.ok, true);
assert.equal(health.redis?.ok, true);
step('health: ok + database + redis');

// 2. register disposable user
const reg = await backend.auth.register({
  name: 'Live Gate', email: EMAIL, password: 'live-pass-123', device_name: 'gate22-1',
});
assert.equal(reg.user.email, EMAIL);
assert.ok(reg.token.includes('|'));
step('register disposable user (201 + token)');

// 3. wrong password → 422 mapping against REAL Laravel validation
await assert.rejects(
  () => backend.http.post('/api/v1/auth/login', { email: EMAIL, password: 'wrong-pass-xyz' }),
  (e) => e instanceof ApiError && e.code === 'VALIDATION_ERROR' && !!e.errors,
);
step('wrong password → 422 VALIDATION_ERROR (real)');

// 4. login → me → authorized request
await backend.auth.setToken(null);
const session = await backend.auth.login({ email: EMAIL, password: 'live-pass-123', device_name: 'gate22-1' });
assert.equal(session.user.email, EMAIL);
const me = await backend.auth.me();
assert.equal(me.email, EMAIL);
assert.ok(me.id > 0);
step('login → me (real Sanctum token)');

// 5. storage upload → signed URL → retrieve bytes
const payload = `live-bytes-${stamp}`;
const obj = await backend.storage.upload(new Blob([payload], { type: 'text/plain' }), {
  bucket: 'gate', fileName: `live-${stamp}.txt`,
});
assert.equal(obj.bucket, 'gate');
assert.ok(obj.key.length > 0);
step(`storage.upload → ${obj.key}`);
const signed = await backend.storage.signedUrl('gate', obj.key.replace(/^gate\//, ''));
assert.ok(signed.includes('/api/v1/storage/gate/') && signed.includes('signature='));
const dl = await fetch(signed);
assert.equal(dl.status, 200);
assert.equal(await dl.text(), payload);
step('storage signed URL retrieves exact bytes');

// 6. function invoke (live console executor, real key, real request id)
// Key-mode function: the key is passed explicitly so it wins over the stored
// user token (documented precedence — see CLIENT_API_CONTRACT §3.3).
const fn = await backend.functions.invoke('gate-hello', { body: { name: 'Ada' }, apiKey: GATE_KEY });
assert.equal(fn.status, 200);
assert.deepEqual(fn.data, { greeting: 'hello, Ada' });
assert.ok(fn.requestId.length > 0);
step(`functions.invoke live (request ${fn.requestId})`);

// 6b. bad key → 401 mapping against the REAL console
await assert.rejects(
  () => backend.functions.invoke('gate-hello', { body: {}, apiKey: 'cp_gate221a.wrongsecret00000000000000000000000000' }),
  (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED',
);
step('functions.invoke with bad key → 401 UNAUTHENTICATED (real)');

// 7. realtime: subscribe → broadcast via REAL Reverb → receive → unsubscribe
const received = [];
const sub = await backend.realtime.subscribe({ channel: 'sdk-orders', onEvent: (e) => received.push(e) });
const trig = await backend.http.post('/api/v1/gate/broadcast', { order_id: 4242 });
assert.equal(trig.data.dispatched, true);
await new Promise((r) => setTimeout(r, 2500));
assert.equal(received.length, 1);
assert.equal(received[0].event, 'order.created');
assert.deepEqual(received[0].data, { id: 4242 });
step('realtime: live Reverb event received');
await sub.unsubscribe();
assert.deepEqual(backend.realtime.activeChannels(), []);
step('realtime.unsubscribe');

// 8. logout → protected request 401s
await backend.auth.logout();
assert.equal(await backend.auth.getToken(), null);
await assert.rejects(() => backend.auth.me(), (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED');
step('logout invalidates token (me → 401)');

console.log('\nLIVE E2E PASS: register, login, me, upload, signed-url, functions, realtime, logout — all against real Laravel');
