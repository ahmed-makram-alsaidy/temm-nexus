# React / Next.js starter (Phase 22C)

Minimal integration example — **not a product**. Demonstrates SDK init, login,
logout, protected-page concept, current user, API request, file upload, and
error handling. Copy the patterns into your app; do not fork this as a base.

## Run (local)

```bash
cd examples/react-next-starter
npm install        # installs @platform/backend-sdk via file: link (private, unpublished)
cp .env.example .env.local
npm run dev
```

## What's here

| File | Pattern |
|---|---|
| `lib/backend.ts` | Browser singleton (`BrowserTokenStore`) vs per-request server client (`MemoryTokenStore`) vs server-only service client (`API_SECRET_KEY`, never `NEXT_PUBLIC_`) |
| `app/login/page.tsx` | Client login → `auth.login()` → `auth.me()` → `/dashboard`; `ApiError` + 422 field errors |
| `app/dashboard/page.tsx` | Protected Server Component: token from HttpOnly cookie → `auth.me()` → `redirect('/login')` on 401 |
| `app/api/logout/route.ts` | Revoke server-side, then clear cookie (logout invalidates both ends) |
| `app/api/session/route.ts` | Mirror the browser token into an HttpOnly cookie for Server Components |
| `components/UploadButton.tsx` | `storage.upload()` with error display |

## SSR rules (binding)

1. Never `new BrowserTokenStore()` on the server — it throws by design.
2. Never a module-global token on the server — one `MemoryTokenStore` client per request.
3. Never `NEXT_PUBLIC_`-prefix a server secret — it ships to the browser bundle.
4. On 401 in a Server Component, redirect to login (token invalid/revoked/expired).

Full guide: `docs/client-sdk/REACT_NEXT.md`.
