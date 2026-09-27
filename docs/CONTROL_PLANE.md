# Control Plane (owner console) — operator guide

Private Laravel + Filament platform at `http://console.test/admin` (local) —
Phases 0–18 features plus the Phase 20 productization. Concept: one workspace per
project — every page under a project is bound to that project's `{record}` id,
connection, and paths. Visual system: `docs/CONTROL_PLANE_DESIGN_SYSTEM.md`.

## Navigation (Phase 20)

```text
Dashboard · Search · Projects (Projects / Project Switcher) · Governance (Team, Audit Log)

Project Workspace — grouped tab bar:
  Access : Users · Security · Roles · Permissions · Sessions
  Data   : Database · SQL Editor · DB Functions · Advanced · Migrations · DB Health
  Build  : Storage · API · API Keys · Functions · Webhooks · Realtime · Queues · Scheduler
  Operate: Logs · Backups · Monitoring · Secrets · Connections · Settings
```

Key surfaces and what they are for:

- **SQL Editor** (`/sql`) — CodeMirror, tabs, saved queries, history, EXPLAIN.
  READ-ONLY by default (always-rollback transactions, statement/lock timeouts,
  500-row cap). Write mode: `sql.execute_write` permission + password re-auth,
  expires after 10 minutes, every run audit-logged; destructive statements need
  the project slug typed. Server vectors (COPY..PROGRAM, role DDL, …) are denied
  in every mode.
- **Database** (`/database`) — table grid with exact counts, visual table
  creation, typed-confirm drop; row browsing/editing on `/records`, schema/FK
  management on `/schema`, CSV import (1k) / export (10k).
- **DB Functions** (`/db-functions`) — SQL/PLpgSQL function CRUD + tester;
  SECURITY DEFINER is warned, never a default.
- **Advanced** (`/db-advanced`) — ERD, indexes (+create), triggers (+create/drop),
  allowlisted extensions, DB roles/RLS display.
- **Functions** (`/functions`) — Server Functions: versioned bounded executors
  (static / parameterized db_lookup / JSON transform) with deploy, rollback,
  tester, invocation logs, auth modes and rate limits. **No arbitrary code
  execution** — documented limitation.
- **API** (`/api`) — snapshot refresh, grouped endpoints, SSRF-guarded tester,
  OpenAPI download. **API Keys** (`/keys`) — show-once, hashed, scoped,
  rotatable, revocable.
- **Secrets** (`/secrets`) — encrypted vault; values are write-only and consumed
  server-side (functions use `{{secrets.NAME}}`); redacted from logs.
- **Logs** (`/logs`) — unified explorer (laravel, auth, audit, functions,
  webhooks, scheduler, realtime, sql, backups, queue) with request-ID correlation.
- **Scheduler** (`/scheduler`) — cron jobs (presets, validator, next-runs) with
  allowlisted targets only. Due sweep runs every minute from `routes/console.php`.
- **Webhooks** (`/webhooks`) — HMAC-signed deliveries, backoff retries, delivery
  log; SSRF guards incl. private ranges and no redirect following.
- **Team** (`/admin/team`) — Owner/Admin/Developer/Observer roles + permission
  matrix. NULL role = no panel access (fail-closed). High-risk permissions are
  explicit only.
- **Search** (`/admin/search`, or press `/`) — scoped results by role.

## Security domains

- Infrastructure owners (`users.is_admin` / assigned `cp_role`) — this console.
- Application users live in each PROJECT database and can never reach this console.
- Granular control-plane permissions: `CpAccess` (`app/Services/ControlPlane/CpAccess.php`).

## Conventions

- Secrets/keys/passwords are never displayed — states only; show-once reveals for
  newly created API keys and generated DB passwords.
- Destructive operations require confirmation (bulk/drop/rollback/delete), typed
  confirmation where specified, and are always audit logged.
- Structural work through the GUI is traceable (`project_schema_changes`) — no
  invisible manual changes.
- Project artisan bridge (`ProjectArtisan`) runs an allowlist only; anything else
  is 403. Migrations are the only structural commands, page-gated with a backup
  precondition for rollback.

## Evidence

- Phase 20: `PHASE20_PLATFORM_PRODUCTIZATION_REPORT.md`,
  `docs/phase20-evidence/phase20-e2e-50-report.json` (50/50),
  `docs/phase20-before|after/`, `tests/Feature/Phase20/*`.
- Phase 18 baseline: `PHASE18_CONTROL_PLANE_REPORT.md`, `scripts/e2e-18s.sh`.
