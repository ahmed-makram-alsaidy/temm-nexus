# Database administration interface (Phase 7)

pgAdmin 4 (`dpage/pgadmin4:9.8`), loopback-only
(`127.0.0.1:${PGADMIN_HTTP_PORT:-5051}:80`), server-mode with master password
required. Pre-registered server entry (`servers.json`, host `postgres`) —
credentials are entered per session, never stored in the repo.

## GATE 7 evidence (2026-09-16, live local run)

- `GET http://127.0.0.1:5051/login` from Windows host → **200 login page**;
  pgAdmin session auth enforced on all non-static routes ✅
- pgAdmin → `postgres:5432` TCP connect from inside the container → OK ✅
- `docker inspect` postgres/redis `Ports`: `{"5432/tcp":null}` /
  `{"6379/tcp":null}` — no host bindings at all ✅
- Local port moved 5050 → 5051: host already had UDP 5050 in use (PID 4180).

## Production access strategy (mandatory, not optional)

pgAdmin must NEVER be anonymously internet-exposed. Pick one before VPS deploy:

1. **Cloudflare Access (recommended)**: publish `db-admin.example.com` through
   Caddy with `forward_auth` to Cloudflare Access; no public pgAdmin port.
2. **VPN**: WireGuard on the VPS; pgAdmin binds the VPN interface only.
3. **Strict IP allow-list**: UFW + Caddy `allow <office-ip>` / `deny all` as a
   minimum, rotated when IPs change.

Additional rules: strong `PGADMIN_DEFAULT_PASSWORD` (32+ chars, secrets file),
`MASTER_PASSWORD_REQUIRED=True`, separate pgAdmin login per operator, and
per-project DB users (Phase 3) so a leaked pgAdmin session still can't cross
project boundaries without that project's own credentials.

## Known local quirk (documented, affects posture notes only)

Docker Desktop (Windows) realizes host port bindings only for containers attached
to at least one **non-internal** network (proven: identical image+flags publish on
`application`, not on `management`/`database`-only). pgAdmin is therefore also
attached to `application`. Side effect: app-network containers can TCP-reach
pgAdmin:80 — harmless, pgAdmin still demands login. Postgres/Redis stay on
internal-only networks with zero published ports (defense in depth, verified).
