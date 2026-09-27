# Owner infrastructure console (Phase 6)

Internal Laravel 13 + Filament 5 app in `apps/owner-console/` (NOT customer-facing).
Derived from the canonical template overlay + Filament; served by the
`owner-console` php-fpm service behind Caddy (`console.test` locally).

## Modules

- **Dashboard** (`ServerStatusWidget`): project counts, DB count/size (via
  read-only `pgsql-monitor` connection = `infra_monitor` + `pg_monitor` grant),
  container-view disk %, failed jobs, last backup record, infra event count.
- **Projects** (Filament `ProjectResource`, auto-generated then constrained):
  name, slug, domain, api_domain, status, db_name, redis_prefix, storage_disk,
  deploy_status. **No secret columns exist** (asserted by test).
- **Database overview** (`DatabaseOverviewWidget`): per-DB size + connections,
  read-only. No destructive actions by design.
- **Queues** (`QueueSummaryWidget`): pending depth per `*:queues:*` key + pointer
  to Horizon for operations. Horizon itself mounts per project in production.
- **Monitoring**: Pulse tables migrated; `pulse:check` scheduled; dashboard links
  to `/pulse` (per-app) — see `docs/MONITORING.md`.
- **Backups** (`backup_records` table + `BackupRecord`): last run, status, size,
  checksum, restore-test status. Display only — no one-click restore (Phase 9).
- **Logs**: links to `docker compose logs <service>` + Laravel daily logs;
  `docs/MONITORING.md` lists exact commands.

Seeded local state: 1 admin (`owner@local.test`), 3 project rows (template-api
active + 2 planned), 1 infra event, 1 backup placeholder.

## GATE 6 evidence (2026-09-16, live local run)

- `GET /admin` guest → **302 to /admin/login**; login page 200 with sign-in form ✅
- `OwnerConsoleAccessTest`: **4 passed** — guest redirect, admin 200 (widgets render
  against live monitor/redis connections), non-admin 403, no secret columns ✅
- Secrets: credentials live only in `.env` files / Docker env, never in DB ✅

## Fixes found while gating

- Overlay `bootstrap/app.php` dropped `use Illuminate\Http\Request;` while still
  type-hinting `Request` → resolved to the `Request` facade alias → fatal TypeError
  on every web request. Re-added the import (also fixed in `projects/_template`).
