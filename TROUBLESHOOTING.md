# Troubleshooting

Fast fixes for the most common self-hosting problems. Most diagnostics end
at the same three commands:

```bash
docker compose -f docker-compose.prod.yml ps          # service health
docker compose -f docker-compose.prod.yml logs app    # application logs
curl -i http://localhost/api/health                   # platform health JSON
```

## Ports busy (80/443 already bound)

`Bind for 0.0.0.0:80 failed: port is already allocated` — another web server
occupies the ports. Either stop it, or change `HTTP_PORT`/`HTTPS_PORT` in
`.env` and `docker compose -f docker-compose.prod.yml up -d`. (Reverse-proxy
in front? Terminate TLS there and point its upstream at this stack.)

## Database unhealthy / "connection refused"

- `postgres` shows `unhealthy` or restarting: check logs
  (`docker compose -f docker-compose.prod.yml logs postgres`) — usually a
  changed `POSTGRES_PASSWORD` on an existing volume (passwords apply only on
  first init) or a full disk.
- App says `SQLSTATE` / cannot connect: verify `DB_HOST=postgres`,
  `DB_PASSWORD` matches what the volume was initialized with, and
  `docker compose -f docker-compose.prod.yml ps` shows postgres healthy.
- **While PostgreSQL is down, every page (including `/api/health`) returns
  HTTP 500** — sessions live in the database, so the request path cannot
  serve a clean 503. This is expected; there is no stack trace exposure
  (APP_DEBUG=false). `php artisan platform:doctor` still works over CLI and
  reports the failure precisely.

## Redis unavailable

App errors about cache/queue/locks: confirm `REDIS_PASSWORD` in `.env`
matches the one Redis started with (same first-init rule), redis is
`healthy`, and `REDIS_HOST=redis`.

## Storage permissions

Uploads fail or the setup wizard flags storage: the app must be able to
write `storage/`. In the production image ownership is set at build; with a
custom bind mount, `chown -R www-data:www-data apps/owner-console/storage`
inside the container (or use the shipped named volume).

## Domain / DNS not resolving

HTTPS needs a real domain with an A/AAAA record pointing at this server —
verify: `dig +short your-domain`. Behind Cloudflare proxy (orange cloud)?
HTTP-01 needs the record to resolve directly to the server or use the
documented DNS-01 approach. See docs/open-source/DOMAIN_TLS.md.

## TLS certificate not issuing

- Confirm `PRIMARY_DOMAIN` in `.env` matches the DNS name exactly.
- Port 80 must stay reachable (ACME HTTP-01) — don't firewall it.
- Check `docker compose -f docker-compose.prod.yml logs caddy` for ACME
  errors; rate limits reset after an hour.
- No domain? The platform intentionally serves plain HTTP on IP — do not
  expect trusted TLS for a bare IP.

## Worker down (Horizon)

Queues stall, dashboard shows no recent jobs:
`docker compose -f docker-compose.prod.yml logs horizon`.
Common causes: Redis password mismatch, low memory (`mem_limit`), or a
failing job crashing the worker — check failed jobs in the admin UI.

## Reverb (websockets) down

Realtime silent: `docker compose -f docker-compose.prod.yml logs reverb`;
verify `REVERB_APP_KEY/SECRET` are set in `.env` and the edge proxies
websocket upgrades (shipped Caddyfile does). Clients must use the public
`REVERB_HOST`/`REVERB_SCHEME`.

## AI key invalid / provider errors

The copilot reports provider errors verbatim in its run view: wrong key,
no quota, or wrong base URL. AI is optional — every non-AI feature works
without any key. Keys are encrypted at rest; re-enter them if rotated.

## Supabase PAT invalid

The connector reports `INVALID_TOKEN`/`INSUFFICIENT_SCOPE` honestly.
Create a new read-scoped PAT and reconnect in the import wizard; nothing is
written to the source either way.

## Setup wizard unreachable / stuck

- Everything redirects to `/setup` but it errors: check `app` logs; the
  wizard verifies the stack itself — fix the failing check it displays.
- Completed setup already? `/setup` locks by design. Authorized reopen:
  `docker compose -f docker-compose.prod.yml exec app php artisan platform:setup-reset`.
- Lost admin password:
  `docker compose -f docker-compose.prod.yml exec app php artisan platform:admin-password <email>`
