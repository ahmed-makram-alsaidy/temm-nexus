# Auth

Source of truth: `docs/CLIENT_API_CONTRACT.md` §3. This page is SDK usage.

## Flows (JS / Dart names; PHP SDK has no user flows by design)

| Flow | JS | Dart | Notes |
|---|---|---|---|
| register | `auth.register({name,email,password})` | `auth.register(name:…, email:…, password:…)` | 201 `{user,token}`; 409 duplicate; 422 invalid |
| login | `auth.login({email,password,device_name?})` | `auth.login(email:…, password:…)` | 403 disabled; 422 wrong password shape; token stored automatically |
| logout | `auth.logout()` | `auth.logout()` | Revokes current token server-side, then clears the store even on failure |
| me | `auth.me()` | `auth.me()` | 401 missing/invalid/revoked/expired |
| forgot | `auth.forgotPassword(email)` | `auth.forgotPassword(email)` | Existence-safe: always resolves |
| reset | `auth.resetPassword({token,email,password})` | `auth.resetPassword(token:…, email:…, password:…)` | Signed email token |
| refresh | `auth.refresh()` | `auth.refresh()` | Only if the project issues expiring tokens |
| revoke | `auth.revokeSessions(id?)` | — (use logout) | Clears local state too |

`device_name` (≤255 chars) labels the token (see Control Plane → Sessions).

## Token handling

- Passwords are sent once over HTTPS, never stored, never logged.
- The SDK attaches the stored user token as `Authorization: Bearer <id|plain>`
  to every call except auth endpoints; per-call `token` overrides it.
- On `UNAUTHENTICATED`: clear cached user, send the user to login. Never retry
  with the same credential. `logout()` after revocation still clears local state.

## XSS / storage implications

`BrowserTokenStore` (localStorage) is readable by any JS on the origin — use
only on trusted first-party origins, prefer short-lived tokens, and consider
HttpOnly-cookie sessions for high-risk apps. Flutter production must use
`SecureTokenStoreAdapter` + `flutter_secure_storage`, never plaintext prefs.
