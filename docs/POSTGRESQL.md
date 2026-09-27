# PostgreSQL architecture (Phase 3)

Server: `postgres:17.11-alpine` on internal `database` network. No host port.
Tuning: `infrastructure/postgres/postgresql.conf` (8 GB VPS: 512 MB shared_buffers,
max_connections 100, pg_stat_statements on, slow-query log > 1000 ms).

## Model

- One server, one database + one role per project (`project_a_db` / `project_a_user` …).
- Owner console gets its own DB/role like any project.
- Least privilege per role: `NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION`,
  owns its DB, `REVOKE CREATE+CONNECT ON DATABASE … FROM PUBLIC` then
  `GRANT CONNECT … TO <owner>`, `REVOKE CREATE ON SCHEMA public FROM PUBLIC` inside the DB.

## Scripts (`scripts/postgres/`, mounted at `/scripts/postgres` in the container)

Invoke from repo root (works on Windows PowerShell and Linux VPS identically):

```powershell
docker compose exec -T postgres sh /scripts/postgres/create-project-db.sh <db> <user> [password]
docker compose exec -T postgres sh /scripts/postgres/rotate-project-password.sh <user> [new]
$env:REVOKE_CONFIRM='<user>'; docker compose exec -T -e REVOKE_CONFIRM=$env:REVOKE_CONFIRM postgres sh /scripts/postgres/revoke-project-access.sh <user>
$env:KILL_CONFIRM='<db>'; docker compose exec -T -e KILL_CONFIRM=$env:KILL_CONFIRM postgres sh /scripts/postgres/terminate-project-connections.sh <db>
$env:DROP_CONFIRM='<db>'; docker compose exec -T -e DROP_CONFIRM=$env:DROP_CONFIRM postgres sh /scripts/postgres/drop-project-db.sh <db> <user>
docker compose exec -T postgres sh /scripts/postgres/inspect.sh {sizes|connections|slow [db]|tables <db>}
```

All mutating scripts validate identifiers (`^[a-z][a-z0-9_]{0,62}$`), refuse to
overwrite, and require confirmation env vars for destructive steps. No secrets in scripts.

## GATE 3 evidence (2026-09-16, live local run)

Created via `create-project-db.sh` (passwords generated, disposable, dropped after Phase 9):

- `gate_a_db` / `gate_a_user`, `gate_b_db` / `gate_b_user`
- A→A: `SELECT count(*) FROM proof_a` → `1` ✅
- B→B: `SELECT 1` → `1` ✅
- A→B: `FATAL: permission denied for database "gate_b_db" / User does not have CONNECT privilege` ✅
- B→A: `FATAL: permission denied for database "gate_a_db"` ✅

Lesson learned during the gate: stock PostgreSQL grants `CONNECT … TO PUBLIC`;
the first script version revoked only `CREATE`, and cross-access succeeded.
Fixed by `REVOKE CONNECT … FROM PUBLIC` + explicit `GRANT CONNECT … TO owner`
(script updated, fix applied to the gate DBs, proof re-run green).
