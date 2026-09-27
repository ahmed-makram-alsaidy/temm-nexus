# Supabase migration runbook (Phase 14) — PREPARATION ONLY

No production credentials, no live connections, no data moved. Migrate one
product at a time, lowest-traffic first.

## Concept map

| Supabase | Target |
|---|---|
| PostgreSQL (per project) | Dedicated `*_db` + role (Phase 3) |
| Auth (GoTrue) | Laravel Sanctum tokens + web session; socialite where needed |
| `auth.users` + password hashes | Import users WITHOUT passwords (bcrypt hashes are portable — GoTrue uses bcrypt; verify per project) → force password reset on first login, OR keep hash if format matches |
| UUID PKs | Preserve exact UUIDs (`$table->uuid('id')->primary()`, `$incrementing=false`, `HasUuids`) |
| RLS policies | Replace with Eloquent scopes + policies + `Auditable` (RLS OFF in app DBs; defense in depth via per-project roles) |
| Storage (files + metadata) | S3-compatible disk (Phase 16); migrate objects with keys intact, rebuild `storage.objects`-equivalent table |
| Edge Functions | Laravel jobs/actions + scheduler; scheduled functions → `routes/console.php` |
| Realtime subscriptions | Reverb channels + Laravel broadcasting authorizers |
| PostgREST | Versioned API resources (`UserResource` pattern) |
| Kong/API gateway | Caddy + Laravel throttles |

## Ordered cutover per product

1. **Freeze & backup**: Supabase point-in-time backup + `pg_dump` of project schema.
2. **Test migration** (staging DB `migrate_test_*`): `pg_dump --schema-only`,
   strip Supabase extensions (`supabase_*` schemas, `auth`, `storage`, `realtime`,
   `vault`, `pgmq` if unused), rewrite `auth.users` references, load into
   `<project>_db`, run Laravel migrations for app tables.
3. **Verify**: row counts per table (`COUNT(*)` source vs target), file count +
   byte total in storage, UUID spot-checks, login flow with a test user
   (password-reset path), RLS-parity checklist (every former policy → policy test).
4. **Android**: hard-coded Supabase URL/anon-key MUST be replaced by app update
   (staged rollout; keep Supabase read-only for old versions during overlap).
5. **Web**: swap env vars (`SUPABASE_*` → `API_BASE_URL` + Sanctum), deploy.
6. **Cutover window**: maintenance page, final delta dump, import, smoke tests,
   DNS/API switch. Target < 60 min for small products.
7. **Rollback**: keep Supabase project untouched for 14 days; rollback = revert
   DNS/env + app version (documented per product at migration time).

## Special risks (must clear per product before scheduling)

- `auth.users` password hashes: confirm bcrypt `$2a$` format → importable; else reset-all.
- UUID preservation vs auto-increment assumptions in new code.
- RLS replacement gaps (most common regression source — test every role).
- Storage metadata (`storage.objects` bucket/owner columns) has no Laravel
  equivalent — design the replacement table FIRST.
- Realtime: audit every `.channel()` subscription; Reverb needs auth endpoints.
- Android hard-coded keys: old app versions break on cutover — version-gate the API.
- Downtime comms + rollback owner (named person) per cutover.

## GATE 14

Plan includes backup ✅, test migration ✅, record-count verification ✅,
file-count verification ✅, auth verification ✅, rollback plan ✅.
Remaining before first real migration: per-product inventory (tables, buckets,
functions, subscriptions) + provisioned offsite backups + scheduled window.
