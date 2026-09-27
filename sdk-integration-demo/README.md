# SDK integration demo (Phase 22J/K — disposable, local only)

Drives the **real built** TypeScript SDK against a disposable in-process mock
of `docs/CLIENT_API_CONTRACT.md`. No production contact, no real project data.

```bash
cd sdk-integration-demo
npm install        # file: link to packages/backend-sdk-js + ws (WS server only)
node demo.mjs      # TS SDK: login, me, list, 422/429, upload, signed-url, functions, realtime, logout
node react-proof.mjs  # React starter call sequence: login → protected fetch → logout → 401
```

## What is proven

- `demo.mjs` — login stores token, `me`, authorized paginated call, 422/429
  mapping incl. `Retry-After`, multipart upload, signed URL, function invoke
  with `X-Request-ID`, realtime subscribe → real event over a local WS server,
  duplicate-subscription guard, credential-free errors, logout invalidates
  server + local state (`me` → 401 after).
- `react-proof.mjs` — the exact SDK call sequence the Next.js starter's
  `login/page.tsx`, `dashboard/page.tsx`, and `api/logout/route.ts` make,
  through both the browser-singleton and per-request server-client shapes.

## What is NOT proven here

- Live Laravel E2E (no PHP runtime on this host; template projects are not
  booted here) — rerun `demo.mjs` with `baseUrl` pointed at a real project to
  upgrade this to a live proof.
- Flutter runtime (no Flutter/Dart toolchain on this host — environment-blocked;
  `packages/backend_sdk_dart/test/` covers the same logic where Dart runs).
