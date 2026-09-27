import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { BackendClient, MemoryTokenStore, ApiError } from '../dist/index.js';

function jsonResponse(status, body, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json', ...headers },
  });
}

function mockFetch(routes) {
  const calls = [];
  const fn = async (url, init = {}) => {
    calls.push({ url, init });
    for (const [match, handler] of routes) {
      if (typeof match === 'string' ? String(url).includes(match) : match.test(String(url))) {
        return handler(new URL(String(url)), init, calls);
      }
    }
    return jsonResponse(404, { message: 'Not Found' });
  };
  return { fn, calls };
}

const USER = { id: 1, name: 'Ada', email: 'ada@example.com', email_verified_at: null, created_at: '2026-09-17T00:00:00Z' };

describe('auth', () => {
  it('login stores the token and me() attaches it', async () => {
    const { fn, calls } = mockFetch([
      ['/api/v1/auth/login', () => jsonResponse(200, { user: USER, token: '1|plain-token' })],
      ['/api/v1/user', (_u, init) => {
        assert.equal(init.headers['Authorization'], 'Bearer 1|plain-token');
        return jsonResponse(200, USER);
      }],
    ]);
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    const session = await backend.auth.login({ email: 'ada@example.com', password: 'password123' });
    assert.equal(session.token, '1|plain-token');
    assert.equal(await backend.auth.getToken(), '1|plain-token');
    const me = await backend.auth.me();
    assert.equal(me.email, 'ada@example.com');
    assert.equal(calls.length, 2);
  });

  it('logout clears the local token even when the server call fails', async () => {
    const { fn } = mockFetch([['/api/v1/auth/logout', () => jsonResponse(401, { message: 'Unauthenticated.' })]]);
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    await backend.auth.setToken('1|stale');
    await assert.rejects(() => backend.auth.logout(), (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED');
    assert.equal(await backend.auth.getToken(), null);
  });

  it('maps 401/422/429 to stable codes with request ids', async () => {
    const { fn } = mockFetch([
      ['/api/v1/auth/login', () => jsonResponse(422, { message: 'Validation failed', errors: { email: ['required'] } }, { 'x-request-id': 'req_1' })],
      // NOTE: '/api/v1/users' must precede '/api/v1/user' — plain `includes`
      // matching would otherwise route list() into the me() handler.
      ['/api/v1/users', () => new Response('slow', { status: 429, headers: { 'retry-after': '2', 'x-request-id': 'req_3' } })],
      ['/api/v1/user', () => jsonResponse(401, { message: 'Unauthenticated.' }, { 'x-request-id': 'req_2' })],
    ]);
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    await assert.rejects(() => backend.auth.login({ email: 'x', password: 'y' }), (e) => {
      assert.equal(e.code, 'VALIDATION_ERROR');
      assert.deepEqual(e.errors, { email: ['required'] });
      assert.equal(e.requestId, 'req_1');
      return true;
    });
    await assert.rejects(() => backend.auth.me(), (e) => e.code === 'UNAUTHENTICATED' && e.requestId === 'req_2');
    await assert.rejects(() => backend.users.list(), (e) => e.code === 'RATE_LIMITED' && e.retryAfterMs === 2000 && e.requestId === 'req_3');
  });

  it('explicit per-call token overrides the stored one', async () => {
    const { fn } = mockFetch([['/api/v1/user', (_u, init) => {
      assert.equal(init.headers['Authorization'], 'Bearer 9|override');
      return jsonResponse(200, USER);
    }]]);
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    await backend.auth.setToken('1|stored');
    await backend.http.get('/api/v1/user', { token: '9|override' });
  });

  it('explicit per-call apiKey wins over the stored user token', async () => {
    const { fn } = mockFetch([['/f/acme/x', (_u, init) => {
      assert.equal(init.headers['Authorization'], 'Bearer cp_explicit.secret');
      return jsonResponse(200, {});
    }]]);
    const backend = new BackendClient({
      baseUrl: 'https://api.example.com', functionsBaseUrl: 'https://f.test',
      projectSlug: 'acme', apiKey: 'cp_configured.other', fetchFn: fn,
    });
    await backend.auth.setToken('1|stored');
    await backend.functions.invoke('x', { apiKey: 'cp_explicit.secret' });
  });

  it('MemoryTokenStore round-trips', async () => {
    const s = new MemoryTokenStore();
    assert.equal(await s.get(), null);
    await s.set('a|b');
    assert.equal(await s.get(), 'a|b');
    await s.clear();
    assert.equal(await s.get(), null);
  });
});
