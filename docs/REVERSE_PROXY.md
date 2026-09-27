# Reverse proxy + domain model (Phase 8)

Choice: **Caddy** (automatic HTTPS, compact config). Nginx equivalent sketched in
`infrastructure/caddy/README.md` as a migration path.

## Domain model (future, no real DNS configured)

| Host | Target |
|---|---|
| `api.project-a.com`, `api.project-b.com` | per-project php-fpm + Reverb |
| `backend.example.com` | owner console |
| `db-admin.example.com` | pgAdmin (behind Cloudflare Access / VPN only) |

Local equivalents use `.test` + `Host:` header or hosts-file entries
(`127.0.0.1 console.test`).

## Requirements coverage

- HTTPS-ready: production Caddyfile uses ACME (HTTP-01 default, DNS-01/Cloudflare
  stanza commented for wildcards); local runs `auto_https off` for `.test`.
- HTTP→HTTPS: global `http:// { redir … }` block in prod file.
- Secure headers: HSTS preload (prod), nosniff, frame options, referrer + permissions policy.
- WebSockets: `@websockets` matcher → `reverse_proxy <app>:6001` (Reverb).
- Request limits: `request_body max_size 28MB` (mirrors PHP `post_max_size`).
- Rate limiting: enforced in Laravel (`throttle:api`, per-route), documented so the
  proxy stays dumb; Caddy `rate_limit` plugin intentionally not required.
- Forwarded headers: template sets `trustProxies(at: '*')` so scheme/IP are correct.

## Critical config rule (found by testing)

Caddy's filesystem root and php-fpm's `SCRIPT_FILENAME` root MUST describe the
same `public/` dir in each container's own path space:

```caddy
root * /srv/owner-console/public        # Caddy's view (bind mount)
php_fastcgi owner-console:9000 {
    root /var/www/html/public       # php-fpm's view (same files)
}
```

Without the inner `root`, every route 404s from `file_server` (observed live).

## GATE 8 evidence (2026-09-16, live local run)

- `caddy validate --config /etc/caddy/Caddyfile` → **Valid configuration** ✅
- `GET http://caddy/api/health` with `Host: console.test` →
  **200** `{"ok":true,"app":"OwnerConsole",…,"database":{"ok":true},"redis":{"ok":true}}` ✅
  (full chain: Caddy → php_fastcgi → Laravel → postgres+redis)
