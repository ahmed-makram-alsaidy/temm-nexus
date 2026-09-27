# Domain & TLS

## With a domain (recommended)

1. Point DNS (A/AAAA) at the server; verify: `dig +short panel.example.com`.
2. Set in .env: `PRIMARY_DOMAIN=panel.example.com` (and `APP_URL=https://panel.example.com`),
   optionally `ACME_EMAIL` for expiry notices.
3. `docker compose -f docker-compose.prod.yml up -d`
4. Caddy obtains a Let's Encrypt certificate automatically (HTTP-01 on
   port 80) and serves HTTPS with redirects.

Wildcard certificates via DNS-01: see deploy/production/Caddyfile.prod for
the commented Cloudflare DNS-01 block.

## Without a domain (IP bootstrap)

The platform intentionally serves **plain HTTP on :80** — fine for a quick
evaluation, never for production data. There is no fake TLS for bare IPs:
browsers would reject self-signed certificates for good reason, so the
platform does not pretend.

Real HTTPS requires a valid domain + DNS; once DNS points at the server and
PRIMARY_DOMAIN is set, HTTPS is automatic.

## Internal traffic

PostgreSQL/Redis never cross the edge; app↔edge traffic stays on the
internal Docker network. Only 80/443 are published.
