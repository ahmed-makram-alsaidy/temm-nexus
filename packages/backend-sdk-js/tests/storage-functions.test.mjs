import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { BackendClient, ApiError } from '../dist/index.js';

function jsonResponse(status, body, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json', ...headers },
  });
}

describe('storage + functions', () => {
  it('upload POSTs multipart to the bucket path', async () => {
    let seen;
    const fn = async (url, init) => {
      seen = { url: String(url), init };
      assert.equal(init.method, 'POST');
      assert.ok(init.body instanceof FormData);
      assert.equal(init.body.get('visibility'), 'private');
      return jsonResponse(201, { bucket: 'avatars', key: 'u/1/p.jpg', size: 10, mime: 'image/jpeg', url: 'https://cdn/x' });
    };
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    const obj = await backend.storage.upload(new Blob(['x'], { type: 'image/jpeg' }), {
      bucket: 'avatars', fileName: 'p.jpg', visibility: 'private',
    });
    assert.ok(seen.url.includes('/api/v1/storage/avatars/upload'));
    assert.equal(obj.key, 'u/1/p.jpg');
  });

  it('signedUrl returns the server URL', async () => {
    const fn = async () => jsonResponse(200, { url: 'https://console.test/sdl/p/b/k?expires=1&signature=abc' });
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    const url = await backend.storage.signedUrl('b', 'k');
    assert.ok(url.includes('/sdl/'));
  });

  it('functions.invoke hits /f/{project}/{slug} on the functions host', async () => {
    let seen;
    const fn = async (url, init) => {
      seen = { url: String(url), init };
      return jsonResponse(200, { greeting: 'hello' }, { 'x-request-id': 'freq_1' });
    };
    const backend = new BackendClient({
      baseUrl: 'https://api.example.com',
      functionsBaseUrl: 'https://console.test',
      projectSlug: 'acme',
      fetchFn: fn,
    });
    const res = await backend.functions.invoke('hello-platform', { body: { name: 'Ada' } });
    assert.equal(seen.url, 'https://console.test/f/acme/hello-platform');
    assert.equal(res.status, 200);
    assert.equal(res.requestId, 'freq_1');
    assert.deepEqual(res.data, { greeting: 'hello' });
  });

  it('functions.invoke without projectSlug throws a clear error', async () => {
    const fn = async () => jsonResponse(200, {});
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    await assert.rejects(() => backend.functions.invoke('x'), /projectSlug/);
  });

  it('function auth failures map cleanly (key / user / disabled / rate limit)', async () => {
    const cases = [
      ['/f/acme/priv', 401, { message: 'API key required.' }, 'UNAUTHENTICATED'],
      ['/f/acme/u', 401, { message: 'Invalid user token.' }, 'UNAUTHENTICATED'],
      ['/f/acme/d', 503, { error: 'Function is disabled.' }, 'UNAVAILABLE'],
      ['/f/acme/r', 429, { message: 'Rate limit exceeded.' }, 'RATE_LIMITED'],
      ['/f/acme/m', 405, { error: 'Method not allowed for this function.' }, 'METHOD_NOT_ALLOWED'],
    ];
    for (const [path, status, body, code] of cases) {
      const fn = async () => jsonResponse(status, body);
      const backend = new BackendClient({
        baseUrl: 'https://api.example.com', functionsBaseUrl: 'https://c.test', projectSlug: 'acme', fetchFn: fn,
      });
      await assert.rejects(() => backend.functions.invoke(path.split('/').pop()), (e) => e instanceof ApiError && e.code === code);
    }
  });
});
