import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { BackendClient, BrowserTokenStore, redactHeaders, qs, ApiError } from '../dist/index.js';

function jsonResponse(status, body, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json', ...headers },
  });
}

describe('http', () => {
  it('sends X-Request-ID and honors the echoed value', async () => {
    let seen;
    const fn = async (url, init) => {
      seen = init.headers['X-Request-ID'];
      return jsonResponse(200, { ok: true }, { 'x-request-id': 'srv_123' });
    };
    const backend = new BackendClient({ baseUrl: 'https://api.example.com/', fetchFn: fn });
    const res = await backend.http.get('/api/health');
    assert.ok(seen && seen.length > 0);
    assert.equal(res.requestId, 'srv_123');
  });

  it('generates a request id when the server echoes none', async () => {
    const fn = async () => jsonResponse(200, { ok: true });
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    const res = await backend.http.get('/api/health');
    assert.ok(res.requestId.length > 0);
  });

  it('supports X-API-Key placement and Bearer default', async () => {
    let got;
    const fn = async (_u, init) => {
      got = { ...init.headers };
      return jsonResponse(200, {});
    };
    const b1 = new BackendClient({ baseUrl: 'https://api.example.com', apiKey: 'cp_abc.secret', fetchFn: fn });
    await b1.http.get('/api/v1/users');
    assert.equal(got['Authorization'], 'Bearer cp_abc.secret');
    assert.equal(got['X-API-Key'], undefined);

    const b2 = new BackendClient({ baseUrl: 'https://api.example.com', apiKey: 'cp_abc.secret', apiKeyPlacement: 'header', fetchFn: fn });
    await b2.http.get('/api/v1/users');
    assert.equal(got['X-API-Key'], 'cp_abc.secret');
  });

  it('times out slow servers with code TIMEOUT', async () => {
    const fn = async (_u, init) => {
      await new Promise((resolve, reject) => {
        init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
      });
      return jsonResponse(200, {});
    };
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn, timeoutMs: 30 });
    await assert.rejects(() => backend.http.get('/api/health'), (e) => e instanceof ApiError && e.code === 'TIMEOUT');
  });

  it('maps aborts to ABORTED', async () => {
    const fn = async (_u, init) => {
      // Faithful fetch semantics: an already-aborted signal rejects immediately.
      if (init.signal?.aborted) throw new DOMException('aborted', 'AbortError');
      await new Promise((resolve, reject) => {
        init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
      });
      return jsonResponse(200, {});
    };
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    const ctrl = new AbortController();
    ctrl.abort();
    await assert.rejects(() => backend.http.get('/api/health', { signal: ctrl.signal }), (e) => e.code === 'ABORTED');
  });

  it('maps network failures to NETWORK_ERROR', async () => {
    const fn = async () => { throw new TypeError('fetch failed'); };
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    await assert.rejects(() => backend.http.get('/api/health'), (e) => e.code === 'NETWORK_ERROR' && e.status === 0);
  });

  it('redacts secrets from headers', () => {
    const out = redactHeaders({ Authorization: 'Bearer 1|secret', 'X-API-Key': 'cp_x.y', 'Content-Type': 'application/json' });
    assert.equal(out['Authorization'], '[REDACTED]');
    assert.equal(out['X-API-Key'], '[REDACTED]');
    assert.equal(out['Content-Type'], 'application/json');
  });

  it('builds contract query strings', () => {
    assert.deepEqual(qs({ page: 2, perPage: 25, search: 'ada', sort: ['name', '-created_at'], filter: { status: 'active' } }), {
      page: 2, per_page: 25, search: 'ada', sort: 'name,-created_at', 'filter[status]': 'active',
    });
  });

  it('BrowserTokenStore throws loudly outside a browser', () => {
    const s = new BrowserTokenStore();
    assert.throws(() => s.get(), /outside a browser/);
    assert.throws(() => s.set('x'), /outside a browser/);
  });
});
