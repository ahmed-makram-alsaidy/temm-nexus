import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { BackendClient, ApiError } from '../dist/index.js';

function jsonResponse(status, body, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json', ...headers },
  });
}

/**
 * SDK security properties (mirrors PHASE 22M server-side checklist):
 * no credential material in errors, no secret logging, revoked/expired
 * tokens surface as UNAUTHENTICATED, server keys never required client-side.
 */
describe('sdk security', () => {
  it('never embeds Authorization material in ApiError', async () => {
    const fn = async () => jsonResponse(401, { message: 'Invalid API key.' });
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', apiKey: 'cp_abc.SUPERSECRET', fetchFn: fn });
    await assert.rejects(() => backend.http.get('/api/v1/users'), (e) => {
      assert.ok(e instanceof ApiError);
      assert.ok(!String(e.message).includes('SUPERSECRET'));
      assert.ok(!JSON.stringify(e).includes('SUPERSECRET'));
      return true;
    });
  });

  it('never sends a server secret implicitly: apiKey only attaches when configured', async () => {
    let headers;
    const fn = async (_u, init) => {
      headers = { ...init.headers };
      return jsonResponse(200, {});
    };
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    await backend.http.get('/api/health');
    assert.equal(headers['Authorization'], undefined);
    assert.equal(headers['X-API-Key'], undefined);
  });

  it('setApiKey rotates credentials on both API and functions clients', async () => {
    const seen = [];
    const fn = async (url, init) => {
      seen.push({ url: String(url), auth: init.headers['Authorization'] });
      return jsonResponse(200, {});
    };
    const backend = new BackendClient({
      baseUrl: 'https://api.example.com', functionsBaseUrl: 'https://c.test', projectSlug: 'acme', fetchFn: fn,
    });
    backend.setApiKey('cp_one.secret1');
    await backend.http.get('/api/v1/users');
    await backend.functions.invoke('f');
    assert.ok(seen.every((s) => s.auth === 'Bearer cp_one.secret1'));
    backend.setApiKey(null);
    await backend.http.get('/api/health');
    assert.equal(seen.at(-1).auth, undefined);
  });

  it('revoked/expired tokens surface as UNAUTHENTICATED with the server message', async () => {
    const fn = async () => jsonResponse(401, { message: 'Invalid user token.' }, { 'x-request-id': 'r1' });
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    await backend.auth.setToken('1|revoked');
    await assert.rejects(() => backend.auth.me(), (e) => {
      return e.code === 'UNAUTHENTICATED' && e.message === 'Invalid user token.' && e.requestId === 'r1';
    });
    // SDK does NOT silently wipe the token: the app decides (logout vs refresh).
    assert.equal(await backend.auth.getToken(), '1|revoked');
  });

  it('error bodies never leak stack traces to the message when the server misbehaves', async () => {
    const fn = async () => jsonResponse(500, { message: 'Server Error', exception: 'Error at ...', trace: ['...'] });
    const backend = new BackendClient({ baseUrl: 'https://api.example.com', fetchFn: fn });
    await assert.rejects(() => backend.http.get('/api/v1/users'), (e) => {
      assert.equal(e.code, 'SERVER_ERROR');
      assert.ok(!String(e.message).includes('trace'));
      return true;
    });
  });
});
