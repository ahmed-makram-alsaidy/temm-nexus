# React / Next.js integration

Example: `examples/react-next-starter` (patterns, not a product).

## Factories (`lib/backend.ts`)

- **Browser** (`"use client"`): singleton `BackendClient` + `BrowserTokenStore`,
  `NEXT_PUBLIC_API_URL` / `NEXT_PUBLIC_API_KEY` (client-safe key only).
- **Server** (Server Components / Route Handlers / Server Actions): fresh
  `BackendClient` + `MemoryTokenStore` **per request**, user token forwarded
  from the HttpOnly session cookie. Never a module-global token on the server
  (cross-user leak). Never `BrowserTokenStore` on the server (it throws).
- **Service** (Route Handlers only): `API_SECRET_KEY` with NO `NEXT_PUBLIC_`
  prefix so it never lands in the browser bundle.

## Patterns

| Need | Pattern |
|---|---|
| Login | Client page → `getBrowserClient().auth.login()` → `auth.me()` → `router.push('/dashboard')`; catch `ApiError`, render `err.errors` for 422 fields |
| Protected page | Server Component reads `backend_token` cookie → `getServerClient(token).auth.me()` → `redirect('/login')` on `UNAUTHENTICATED` |
| API request | Same as above; `qs()` params for lists |
| Upload | `UploadButton` → `storage.upload(file, { bucket })` → show `ApiError.message (code)` |
| Logout | `POST /api/logout`: `backend.auth.logout()` server-side (revokes token), then delete the cookie. Browser store is cleared by the same call path. Logout must invalidate **both** ends — verify `me()` now 401s |

## SSR rules (binding)

1. Server secrets never get the `NEXT_PUBLIC_` prefix.
2. One token store per server request; singleton only in the browser.
3. On 401 server-side, drop the session and redirect — never retry with the
   same credential, never render the token.
