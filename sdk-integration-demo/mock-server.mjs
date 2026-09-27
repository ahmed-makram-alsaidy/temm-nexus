/**
 * Disposable mock of the canonical contract (docs/CLIENT_API_CONTRACT.md).
 * In-memory only: one demo user, one revokable token, request-id echo.
 * Localhost only. No production contact.
 */
import http from 'node:http';

export const DEMO_USER = {
  id: 7,
  name: 'Demo Ada',
  email: 'ada@example.com',
  email_verified_at: null,
  created_at: '2026-09-17T00:00:00.000000Z',
};

const VALID_TOKEN = '7|demo-token';
let revoked = false;

function send(res, status, body, extraHeaders = {}) {
  const payload = body === null ? '' : JSON.stringify(body);
  res.writeHead(status, {
    'content-type': 'application/json',
    'x-request-id': extraHeaders['x-request-id'] ?? `mock_${Date.now().toString(36)}`,
    ...extraHeaders,
  });
  res.end(payload);
}

function readBody(req) {
  return new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      if (!raw) return resolve(null);
      try {
        resolve(JSON.parse(raw));
      } catch {
        resolve(raw);
      }
    });
  });
}

function bearer(req) {
  const h = req.headers['authorization'] ?? '';
  if (h.startsWith('Bearer ')) return h.slice(7);
  return req.headers['x-api-key'] ?? new URL(req.url, 'http://x').searchParams.get('key') ?? null;
}

export function startMockApi() {
  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x');
    const rid = req.headers['x-request-id'] && /^[A-Za-z0-9\-_:.]{1,128}$/.test(req.headers['x-request-id'])
      ? req.headers['x-request-id']
      : `mock_${Math.random().toString(36).slice(2)}`;
    const body = await readBody(req);
    const token = bearer(req);
    const authed = token === VALID_TOKEN && !revoked;
    const keyAuthed = (token?.startsWith('cp_') || authed) ?? false;

    if (req.method === 'GET' && url.pathname === '/api/health') {
      return send(res, 200, { ok: true, app: 'mock', time: new Date().toISOString(), database: { ok: true }, redis: { ok: true } }, { 'x-request-id': rid });
    }
    if (req.method === 'POST' && url.pathname === '/api/v1/auth/login') {
      if (body?.password !== 'password123' || !body?.email) {
        return send(res, 422, { message: 'Validation failed', errors: { password: ['The password field is required.'] } }, { 'x-request-id': rid });
      }
      revoked = false;
      return send(res, 200, { user: DEMO_USER, token: VALID_TOKEN }, { 'x-request-id': rid });
    }
    if (req.method === 'POST' && url.pathname === '/api/v1/auth/logout') {
      if (!authed) return send(res, 401, { message: 'Unauthenticated.' }, { 'x-request-id': rid });
      revoked = true;
      return send(res, 200, {}, { 'x-request-id': rid });
    }
    if (req.method === 'GET' && url.pathname === '/api/v1/user') {
      if (!authed) return send(res, 401, { message: revoked ? 'Invalid user token.' : 'Unauthenticated.' }, { 'x-request-id': rid });
      return send(res, 200, DEMO_USER, { 'x-request-id': rid });
    }
    if (req.method === 'GET' && url.pathname === '/api/v1/users') {
      if (!authed) return send(res, 401, { message: 'Unauthenticated.' }, { 'x-request-id': rid });
      if (url.searchParams.get('trigger429') === '1') {
        return send(res, 429, { message: 'Too Many Requests' }, { 'x-request-id': rid, 'retry-after': '1' });
      }
      const page = Number(url.searchParams.get('page') ?? 1);
      return send(res, 200, {
        data: [DEMO_USER],
        meta: { current_page: page, last_page: 1, per_page: 15, total: 1 },
      }, { 'x-request-id': rid });
    }
    const upload = url.pathname.match(/^\/api\/v1\/storage\/([^/]+)\/upload$/);
    if (req.method === 'POST' && upload) {
      if (!authed && !keyAuthed) return send(res, 401, { message: 'Unauthenticated.' }, { 'x-request-id': rid });
      return send(res, 201, { bucket: upload[1], key: 'u/7/demo.bin', size: 5, mime: 'application/octet-stream', url: `http://x/storage/${upload[1]}/u/7/demo.bin`, temporary_url: null }, { 'x-request-id': rid });
    }
    const signed = url.pathname.match(/^\/api\/v1\/storage\/([^/]+)\/signed-url$/);
    if (req.method === 'POST' && signed) {
      if (!authed && !keyAuthed) return send(res, 401, { message: 'Unauthenticated.' }, { 'x-request-id': rid });
      return send(res, 200, { url: `http://127.0.0.1/sdl/demo/${signed[1]}/k?expires=1&signature=mock` }, { 'x-request-id': rid });
    }
    const fn = url.pathname.match(/^\/f\/([^/]+)\/([^/]+)$/);
    if (fn && ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(req.method)) {
      if (!keyAuthed) return send(res, 401, { message: 'API key required.' }, { 'x-request-id': rid });
      return send(res, 200, { greeting: `hello, ${body?.name ?? 'world'}` }, { 'x-request-id': rid });
    }
    return send(res, 404, { message: 'Not Found' }, { 'x-request-id': rid });
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => resolve(server));
  });
}
