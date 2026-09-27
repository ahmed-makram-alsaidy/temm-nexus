/**
 * Phase 22J integration demo (TypeScript SDK) + 22M client-side security spot checks.
 * Drives the REAL built SDK against the disposable mock contract server.
 * Also proves realtime subscribe → receive over a local Pusher-protocol WS server.
 *
 * Run: npm install && node demo.mjs   (exits non-zero on any failure)
 */
import assert from 'node:assert/strict';
import { WebSocketServer } from 'ws';
import { BackendClient, ApiError } from '@platform/backend-sdk';
import { startMockApi } from './mock-server.mjs';

const step = (n) => console.log(`ok - ${n}`);

const api = await startMockApi();
const apiPort = api.address().port;
const apiUrl = `http://127.0.0.1:${apiPort}`;

// Minimal Pusher-protocol WS server: handshake, subscribe ack, one test event.
const wss = new WebSocketServer({ port: 0, host: '127.0.0.1' });
await new Promise((r) => wss.on('listening', r));
const wsPort = wss.address().port;
wss.on('connection', (ws) => {
  ws.send(JSON.stringify({ event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '7.7' }) }));
  ws.on('message', (raw) => {
    const frame = JSON.parse(String(raw));
    if (frame.event === 'pusher:subscribe') {
      setTimeout(() => {
        ws.send(JSON.stringify({
          event: 'order.created',
          channel: frame.data.channel,
          data: JSON.stringify({ message: { id: 2 } }),
        }));
      }, 20);
    }
  });
});

try {
  const backend = new BackendClient({
    baseUrl: apiUrl,
    apiKey: 'cp_demo.clientsafekey',
    projectSlug: 'demo',
    functionsBaseUrl: apiUrl,
    realtime: { wsUrl: `ws://127.0.0.1:${wsPort}/app/demo?protocol=7` },
  });

  // health (no auth)
  const health = await backend.health();
  assert.equal(health.ok, true);
  step('health probe');

  // login → me → authorized API call
  const session = await backend.auth.login({ email: 'ada@example.com', password: 'password123' });
  assert.equal(session.user.email, 'ada@example.com');
  step('auth.login stores token');
  const me = await backend.auth.me();
  assert.equal(me.id, 7);
  step('auth.me with stored token');
  const page = await backend.users.list({ page: 1 });
  assert.equal(page.data.length, 1);
  assert.equal(page.meta.total, 1);
  step('authorized list call (paginator envelope)');

  // 422 + 429 mapping against the mock
  await assert.rejects(
    () => backend.auth.login({ email: 'ada@example.com', password: 'nope' }),
    (e) => e instanceof ApiError && e.code === 'VALIDATION_ERROR' && !!e.errors,
  );
  step('422 validation mapping');
  await assert.rejects(
    () => backend.http.get('/api/v1/users', { query: { trigger429: '1' } }),
    (e) => e instanceof ApiError && e.code === 'RATE_LIMITED' && e.retryAfterMs === 1000,
  );
  step('429 rate-limit mapping + Retry-After');

  // storage: upload + signed url
  const obj = await backend.storage.upload(new Blob(['hello'], { type: 'text/plain' }), {
    bucket: 'avatars', fileName: 'demo.txt',
  });
  assert.equal(obj.bucket, 'avatars');
  step('storage.upload multipart');
  const signed = await backend.storage.signedUrl('avatars', 'u/7/demo.bin');
  assert.ok(signed.includes('/sdl/'));
  step('storage.signedUrl');

  // functions: invoke (user token accepted by mock as key-capable credential)
  const res = await backend.functions.invoke('hello-platform', { body: { name: 'Ada' } });
  assert.equal(res.status, 200);
  assert.deepEqual(res.data, { greeting: 'hello, Ada' });
  assert.ok(res.requestId.length > 0);
  step(`functions.invoke (request ${res.requestId})`);

  // realtime: subscribe → receive → duplicate guard → unsubscribe
  const received = [];
  const sub = await backend.realtime.subscribe({ channel: 'orders', onEvent: (e) => received.push(e) });
  await new Promise((r) => setTimeout(r, 300));
  assert.equal(received.length, 1);
  assert.equal(received[0].event, 'order.created');
  assert.deepEqual(received[0].data, { id: 2 });
  step('realtime.subscribe receives event');
  await backend.realtime.subscribe({ channel: 'orders', onEvent: () => {} });
  assert.deepEqual(backend.realtime.activeChannels(), ['orders']);
  step('realtime duplicate-subscription guard');
  await sub.unsubscribe();
  step('realtime.unsubscribe');

  // security spot checks (22M client side)
  await assert.rejects(
    () => backend.http.get('/api/v1/users', { token: 'stale' }),
    (e) => e instanceof ApiError && !String(e.message).includes('cp_demo'),
  );
  step('no credential material in errors');

  // logout invalidates server state; me() must now 401
  await backend.auth.logout();
  assert.equal(await backend.auth.getToken(), null);
  await assert.rejects(() => backend.auth.me(), (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED');
  step('auth.logout invalidates local + server state (me → 401)');

  console.log('\nDEMO PASS: login, me, authorized call, upload, signed-url, functions, realtime, logout');
} finally {
  wss.close();
  api.close();
}
