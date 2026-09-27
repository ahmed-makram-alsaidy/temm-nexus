# Generated Client Setup

> Route: `/admin/projects/{record}/connect` (extended) · Permission: `projects.view`

The Phase 22 Connect page became environment-aware (24I): all generated
config reflects the active environment's `api_base_url` (falling back to the
project `api_domain`), selected via the workspace environment switcher.

## Generated config (24I.2)

`ClientSetupService::configFor(project, environment)` returns SAFE values
only: api_url, functions base (request-host based, per the 22.1 gate fix),
realtime WS URL, project slug, environment slug, storage endpoint. Secret
material never appears — asserted by test (`assertStringNotContainsString(
'secret'|'password')`).

## Copy-paste setup (24I.3)

Snippets per client — **JavaScript/TypeScript, React/Next.js, Flutter/Dart,
PHP (server-to-server)** — use the real Phase 22 SDK surface
(`BackendClient.auth.login/me`, `storage.upload`, `functions.invoke`,
`functions()->invoke`, `getBrowserClient()`), never invented method names.
Tested in `ClientSetupTest`.

## Env file generation (24I.4)

`.env.example (JS/PHP)`, `dart-define` values and `Next.js public env` — all
copyable from the page, all marked CLIENT-SAFE for public values and
explicitly warning that server secrets never ship in public env.

## Connection test (24I.5)

`ClientSetupService::testConnection` performs safe calls only:

1. `GET {api}/api/health` — API reachability;
2. `GET {api}/api/v1/user` unauthenticated — auth bootstrap (401 expected);
3. realtime endpoint configuration check (honest `unavailable` when unset).

SSRF guard: only the project's own api host is contacted, only http(s)
schemes allowed, and internal/metadata ranges (127/8, 10/8, 172.16/12,
192.168/16, 169.254/16, ::1, `localhost`, cloud metadata hostnames) are
refused before any request is made. Every test is audited
(`CLIENT_CONNECTION_TESTED`).
