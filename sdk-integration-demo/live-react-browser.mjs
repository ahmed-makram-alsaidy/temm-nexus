/**
 * Phase 22.1 REACT BROWSER proof — real headless Chrome drives the REAL
 * Next.js starter (examples/react-next-starter, `next dev`) against the REAL
 * live Laravel project. No mocks. Disposable user only.
 *
 * Flow: pre-register via API → /login form → SDK login → /dashboard shows
 * live user data → screenshot → Sign out → /login → /dashboard redirects away.
 *
 * Run:  node live-react-browser.mjs   (needs the starter on :3100 + live API)
 */
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const step = (n) => console.log(`ok - ${n}`);
const HERE = path.dirname(fileURLToPath(import.meta.url));
const APP = 'http://127.0.0.1:3100';
const API = process.env.LIVE_API_URL ?? 'http://127.0.0.1:8123';
const SHOT = path.join(HERE, '..', 'docs', 'phase22', 'live-react-1440.png');
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const stamp = Date.now().toString(36);
const EMAIL = `browser-${stamp}@example.com`;
const PASSWORD = 'live-pass-123';

// 0. disposable user, straight at the live API
{
  const r = await fetch(`${API}/api/v1/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ name: 'Browser User', email: EMAIL, password: PASSWORD, password_confirmation: PASSWORD }),
  });
  assert.equal(r.status, 201);
}
step('disposable user registered on live Laravel');

let PORT = 0;
for (let p = 9233; p < 9270 && !PORT; p++) {
  try { await fetch(`http://127.0.0.1:${p}/json/version`); } catch { PORT = p; }
}
assert.ok(PORT, 'free debugging port');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'gate22-1-react-'));
const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--user-data-dir=${profile}`, `--remote-debugging-port=${PORT}`,
  '--unsafely-treat-insecure-origin-as-secure=http://console.test,http://127.0.0.1:3100',
  '--window-size=1440,900', '--hide-scrollbars', 'about:blank',
], { stdio: 'ignore' });

const jsErrors = [];
let cdp;
try {
  let target = null;
  for (let i = 0; i < 100 && !target; i++) {
    await new Promise((r) => setTimeout(r, 200));
    try {
      const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
      target = (Array.isArray(list) ? list : []).find((t) => t.type === 'page') ?? null;
    } catch { /* retry */ }
  }
  assert.ok(target?.webSocketDebuggerUrl, 'devtools endpoint up');

  cdp = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r, j) => { cdp.onopen = r; cdp.onerror = j; });
  let id = 0;
  const pending = new Map();
  cdp.onmessage = (m) => {
    const msg = JSON.parse(String(m.data));
    if (msg.id && pending.has(msg.id)) {
      const { r, j } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? j(new Error(JSON.stringify(msg.error))) : r(msg.result);
    }
    if (msg.method === 'Log.entryAdded' && msg.params?.entry?.level === 'error') {
      jsErrors.push(msg.params.entry.text ?? 'log-error');
    }
    if (msg.method === 'Runtime.exceptionThrown') {
      jsErrors.push(msg.params?.exceptionDetails?.text ?? 'exception');
    }
  };
  const send = (method, params = {}) => new Promise((r, j) => {
    const myId = ++id;
    pending.set(myId, { r, j });
    cdp.send(JSON.stringify({ id: myId, method, params }));
  });
  const evaluate = async (expression) => {
    const res = await send('Runtime.evaluate', { expression, returnByValue: true });
    if (res.exceptionDetails) throw new Error(`evaluate failed: ${res.exceptionDetails.text}`);
    return res.result?.value;
  };
  const gotoLoad = async (url) => {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Log.enable');
    const arrived = new Promise((resolve) => {
      const h = (m) => {
        const msg = JSON.parse(String(m.data));
        if (msg.method === 'Page.loadEventFired') { cdp.removeEventListener('message', h); resolve(); }
      };
      cdp.addEventListener('message', h);
      setTimeout(resolve, 15000);
    });
    await send('Page.navigate', { url });
    await arrived;
    await new Promise((r) => setTimeout(r, 2500));
  };
  const waitUrl = async (re, timeoutMs = 30000) => {
    const t0 = Date.now();
    for (;;) {
      const url = await evaluate('window.location.href');
      if (re.test(url)) return url;
      if (Date.now() - t0 > timeoutMs) throw new Error(`timeout waiting for ${re} (at ${url})`);
      await new Promise((r) => setTimeout(r, 500));
    }
  };

  // 1. login form → SDK → dashboard
  await gotoLoad(`${APP}/login`);
  assert.equal(await evaluate(`!!document.querySelector('input[type="email"]')`), true);
  await evaluate(`(() => {
    // React controlled inputs: bypass via the native value setter so the
    // change actually reaches React state (execCommand alone does not).
    const set = (sel, v) => {
      const el = document.querySelector(sel);
      const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      setter.call(el, v);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    set('input[type="email"]', ${JSON.stringify(EMAIL)});
    set('input[type="password"]', ${JSON.stringify(PASSWORD)});
    return true;
  })()`);
  await evaluate(`document.querySelector('form').requestSubmit()`);
  await waitUrl(/\/dashboard/);
  step('login form → SDK → Laravel → /dashboard');

  // 2. dashboard shows LIVE user data (server-rendered from the session cookie)
  const dashText = await evaluate('document.body.innerText');
  assert.ok(dashText.includes('Browser User'), 'dashboard shows live user name');
  assert.ok(dashText.includes(EMAIL), 'dashboard shows live user email');
  step('dashboard renders live Laravel user data');
  fs.mkdirSync(path.dirname(SHOT), { recursive: true });
  const shot = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(SHOT, Buffer.from(shot.data, 'base64'));
  step(`dashboard screenshot saved (${fs.statSync(SHOT).size} bytes)`);

  // 3. sign out → login; protected page redirects away
  await evaluate(`document.querySelector('form[action="/api/logout"] button').click()`);
  await waitUrl(/\/login/);
  step('sign out → /login');
  await gotoLoad(`${APP}/dashboard`);
  await waitUrl(/\/login/);
  step('protected /dashboard after logout redirects to /login (state cleared)');

  assert.equal(jsErrors.length, 0, `no JS errors (got: ${jsErrors.slice(0, 3).join(' | ')})`);
  step('no JS errors in the starter flow');
  console.log('\nREACT BROWSER PROOF PASS');
} finally {
  try { cdp?.close(); } catch { /* ignore */ }
  try { chrome.kill('SIGKILL'); } catch { /* ignore */ }
  await new Promise((r) => setTimeout(r, 2500));
  for (let i = 0; i < 5; i++) {
    try { fs.rmSync(profile, { recursive: true, force: true, maxRetries: 3, retryDelay: 500 }); break; }
    catch { await new Promise((r) => setTimeout(r, 1000)); }
  }
}
