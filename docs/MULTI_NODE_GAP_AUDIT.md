# Multi-Node Gap Audit (Phase 21B)

Scope: everything the Control Plane assumes about "one host". Method: code
search for host literals, container-name defaults, and shared-filesystem
paths in `apps/owner-console/{app,config}` plus `docker-compose.yml`.
Verified 2026-09-17 against the local stack. No production contact.

Verdicts: **PASS** (multi-node safe) · **CHANGE REQUIRED** (works today,
must change before a service moves) · **BLOCKER** (prevents the profile).

## Endpoint resolution

| # | Area | Finding | Verdict |
|---|------|---------|---------|
| 1 | Project PostgreSQL | `ProjectConnectionManager` resolves per project: `projects.db_host/db_port` → `PROJECT_DB_HOST/PORT` env; re-resolves mid-process on change (no restart). Proven against a separate container (Phase 21 report). | PASS |
| 2 | Project Redis | `ProjectRedisManager` builds per-project `project_redis_{id}` connections via the same override chain (mapping → columns → `REDIS_HOST/PORT`). Proven against a separate container. | PASS |
| 3 | Project Reverb | `InfrastructureMapper::reverbEndpoint()` chain exists; owner-console `config/reverb.php` + `config/broadcasting.php` are fully env-driven. Proven against a separate server process on :8091. | PASS |
| 4 | Owner-console DB/Redis/cache/session | `config/database.php` + `.env` (`DB_HOST`, `REDIS_HOST`, `CACHE_STORE=redis`, `SESSION_DRIVER=database`). Defaults are `127.0.0.1` but every value is env-overridable. | PASS |
| 5 | Mail | `config/mail.php` env-driven (`MAIL_HOST`, default loopback). Moving the app host only needs `MAIL_HOST` pointed at Mailpit/relay. | PASS |
| 6 | Backups (`ProjectBackupService::trigger`) | `pg_dump -h` now uses `InfrastructureMapper::dbEndpoint()` (fixed in Phase 21 — previously raw `PROJECT_DB_HOST`, which would have dumped the OLD host after a move). Destination is still local `/backups` only. | CHANGE REQUIRED (destination) |
| 7 | Backup size probe (`stats()`) | Uses `pgsql-monitor` on `DB_HOST`; fails closed to 0 bytes if the DB moved and the monitor role has no route. Cosmetic. | CHANGE REQUIRED |
| 8 | DB health size/activity | Same monitor connection as #7. | CHANGE REQUIRED |
| 9 | Webhook SSRF guard | `WebhookService` refuses localhost/private/metadata targets. Correct today; a split profile calling node-internal callback URLs would be blocked until the node LAN is allowlisted. | CHANGE REQUIRED (when splitting) |
| 10 | Caddy upstream names | `Caddyfile` proxies to `owner-console:9000` / `:6001` (compose DNS). Moving Reverb/app requires Caddyfile + `REVERB_*` updates. No Reverb server is supervised in compose today (port 6001 vs default server 8080 mismatch) — realtime needs a managed node definition first. | CHANGE REQUIRED |
| 11 | Horizon/workers | `horizon:snapshot` is scheduled but **no Horizon or queue worker process runs** in the current containers (`ps` shows php-fpm only). Queues work via `QUEUE_CONNECTION=redis`, but a worker node must run supervised `horizon`/`queue:work`. | CHANGE REQUIRED |

## Shared-filesystem assumptions (the real blockers)

| # | Area | Finding | Verdict |
|---|------|---------|---------|
| 12 | Project checkouts | `ControlPlanePaths::projectDir()` (`/projects/<slug>` bind mount), `ProjectLogReader`, `ProjectStorageManager`, `ProjectArtisan`, `ServerStatusWidget` (`glob('/projects/*')`) all read the app host's disk. If the app moves off the console host, code view, logs, storage browser and artisan probes go blind. | BLOCKER (for app split; fine on single node) |
| 13 | Storage driver | `FILESYSTEM_DISK=local` default + checkout-local `storage/control-plane`. Distributed profile needs R2/S3 (config keys exist in Laravel; buckets/policy UI work is pending). | BLOCKER (for distributed) |
| 14 | Backup destination | Local `/backups` bind mount; prune/verify assume local files. Split-DB restores from another host need object storage + endpoint-aware jobs. | CHANGE REQUIRED |
| 15 | SQLite-isms | None in app code paths (pgsql-only catalog queries are project-scoped). | PASS |

## Deploy / ops mechanics

| # | Area | Finding | Verdict |
|---|------|---------|---------|
| 16 | Opcache | Container PHP runs `opcache.validate_timestamps=Off`. Any code deploy (including single-node) needs `route:clear`/`view:clear` + worker reload — proven the hard way in Phase 21 (new routes 404'd until `optimize:clear` + container restart). Multi-node deploys MUST roll every app node. | CHANGE REQUIRED (runbook) |
| 17 | Stale route cache | A `route:cache` artifact existed in `bootstrap/cache` and hid new routes. Do not `route:cache` with Filament discovery pages, or rebuild it on every deploy. | CHANGE REQUIRED (runbook) |
| 18 | Scheduler | Cron sweeps (`cp-cron-due-sweep`, `cp-webhook-retries`, `cp-node-stale-sweep`) run in-process; multi-app needs `onOneServer()` + shared cache lock (Redis is already shared — wire it). | CHANGE REQUIRED |
| 19 | Secrets | Project secrets encrypted at rest; node tokens sha256-hashed; no plaintext keys in node/service records (verified in migration + code). | PASS |
| 20 | No Docker socket / SSH in web app | Verified: no `docker.sock` mount on `owner-console`, no `ssh`/`exec` remotes in `app/` (only local `pg_dump`/`sha256sum`/`pg_restore --list` for backups). | PASS |

## Bottom line

- **Single node**: no blockers. Ship as-is.
- **Split database**: items 6 (done: endpoint; pending: destination), 9, 10, 11.
- **Distributed**: items 12, 13 first; then 6–11, 14, 16–18 as runbook work.
