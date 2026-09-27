# Migration Center

> Route: `/admin/projects/{record}/migration-center` · Permission: view `projects.view`, manage `migrations.manage`

## Purpose

A project-scoped Migration Center that can analyze and migrate existing
Supabase-backed projects — generic by design, extendable to other source
types without rewriting the engine.

## Source connection

`MigrationSource` rows hold: type (`supabase`, `sqlite` for fixtures),
display name, source_ref (display only), connection metadata
(host/port/database/username, optional imported manifest) and **secret
references** (vault secret NAMES — no raw secrets in this table).
Sources are always created READ-ONLY.

## Read-only guarantee

The Supabase adapter sets `default_transaction_read_only = on` at connect and
runs every inventory/stream query inside explicit `BEGIN TRANSACTION READ
ONLY`. Analyze / inventory / preview / export / reconciliation never mutate
the source. The UI displays a `READ-ONLY SOURCE` badge.

## Analysis (24A.3–24A.4)

Analyze produces an **immutable, versioned** `MigrationAnalysis` + items:
schemas, tables (columns/PK/FK/indexes/row estimates/RLS flag), views,
materialized views, enums, functions (security, auth/vault/net dependency),
triggers, RLS policies, extensions, auth domain (users/identities/providers/
hash strategy), storage (buckets/object counts/bytes), realtime publication,
cron jobs — plus edge functions and client dependencies from an imported
manifest. Re-analysis never overwrites history.

## Compatibility (24A.5)

Classifications: `DIRECT`, `SUPPORTED_WITH_TRANSFORM`,
`APPLICATION_CONVERSION_REQUIRED`, `EXTERNAL_INTEGRATION`, `NEEDS_REVIEW`,
`BLOCKED`, `NOT_APPLICABLE`. Discovery alone never implies compatibility.

## Risks (24A.6)

Automatic flags include: auth hash strategy unresolved, RLS auth.*
dependency, vault.* dependency, provider storage URLs, unknown RPC caller,
edge function provider secrets, cross-schema dependencies, unsupported
extensions, matview refresh dependency, large tables, missing PK, financial
tables (money columns / ledger-like names), hardcoded provider URLs.

## Plan (24A.7–24A.8)

`MigrationPlan` + items with strategy, transform, stage, validation method
and status (`DISCOVERED → MAPPED → READY → MIGRATED → VALIDATED`, plus
`NEEDS_REVIEW / BLOCKED / SKIPPED_WITH_REASON`). Ordering is a FK
topological sort (auth first; cycles land in a catch-up stage for review) —
never alphabetical.

## Runs & validation (24A.9–24A.11)

`MigrationRunManager::start/execute` supports dry run (no writes, no target
connect), rehearsal (disposable target, schema built incl. enums + auth
users table, reset allowed) and real (existing schema only). Runs are
resumable (completed items never re-run), cancel-safe (cancel honored
between items) and per-item failing. Validation suite: row counts, FK
orphans, PK preservation, sequence correctness, checksums, status coverage,
auth linkage, storage manifest, custom validators, financial reconciliation
hook. "Rehearse Clean Migration" resets an explicitly disposable target
twice and compares results for determinism.
## Determinism regression

A regression test re-analyzes a real production-shaped source read-only
(local snapshot + local Supabase-shaped stack) and compares results for
determinism against a documented inventory.
