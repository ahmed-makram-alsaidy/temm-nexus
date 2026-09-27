# Schema Diff

> Route: `/admin/projects/{record}/schema-diff` · Permission: `database.read`

## Snapshots & fingerprint (24E.1)

`SchemaDiffService::introspect` reads a live PostgreSQL database (read-only)
into a normalized inventory: tables (columns/types/nullability/defaults/PK/
FK/indexes/RLS flag), views, functions, triggers, extensions.
`SchemaFingerprint::compute` produces a deterministic sha256 over the
normalized shape — ordering-insensitive, row-count-insensitive; any schema
change (column, type, nullability, default, PK, FK, index, view, function,
trigger, extension) changes the fingerprint. `schema_snapshots` keeps the
history per project/environment with an audit entry.

## Diff classification (24E.2)

`SchemaDiffService::diff(a, b)` classifies `ADDED` / `REMOVED` / `CHANGED`
with severity:

| Severity | Examples |
|---|---|
| SAFE | table added, nullable column added, FK added |
| REVIEW | NOT NULL change, function/trigger/view dropped |
| DANGEROUS | drop table, drop column, type narrowing, PK change, FK removal, unique constraint removal |

Classification is advisory: the platform never applies destructive changes
automatically.

## Migration drift (24E.3)

`SchemaDiffService::migrationDrift` compares a live database against the
project checkout's migration files: tables that exist in the DB but are
created by no migration file are flagged `REVIEW` (manual change), and
applied migration versions are listed as INFO. Environment migration
version differences surface through environment snapshots.

## Deployment guard (24E.4)

Dangerous findings feed the Production Readiness Center via
`ReadinessService::recordDrift` — unresolved dangerous drift becomes a RED
machine check that **blocks production**.

## UI (24E.5)

Snapshot list (id, environment, source, fingerprint, captured), plus a
structured diff panel (severity badge, kind, object, change, detail) sorted
dangerous-first, capped at 200 rows for large schemas.
