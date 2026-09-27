# Reusable Migration Engine

> Location: `app/Services/ControlPlane/Migration/` · 24J

## Architecture (24J.1)

```
SourceAdapter  →  TransformPipeline  →  TargetAdapter
        ↘ PlanGenerator ↗          ↘ ValidatorSuite ↗
                 MigrationRunManager (RunManager)
```

Contracts in `Contracts/`: `SourceAdapter` (connect read-only, inventory,
fingerprint, countRows, streamRows, streamAuthUsers) and `TargetAdapter`
(ensureTable, ensureEnum, applyForeignKeys, idempotent insertBatch,
truncateTable — guarded upstream —, counts, sequences, orphans, checksums).

Adapters: `SupabaseSourceAdapter` (PostgreSQL/Supabase-shaped, production
never contacted by the platform), `SqliteSourceAdapter` (test fixtures +
disposable dry-runs), `PostgresTargetAdapter`, `SqliteTargetAdapter`. The
factory is registry-based — new source types plug in without engine changes.

## Mapping manifest (24J.2)

`MigrationTemplates::validateManifest` validates operator-supplied manifests
(`mappings[]` = source_table, target_table, key_strategy, column_map,
transform, transform_config); `applyManifest` applies them onto plan items.
No project names are ever encoded in the engine (scan-tested).

## Transform library (24J.3–24J.4)

Deterministic, pure transforms: `direct_copy, rename, enum_mapping,
json_normalization, uuid_preserve, int_preserve, relation_remap, url_rewrite,
auth_identity_transform, legacy_archive, timestamp_normalize, utf8_text` —
plus a runtime registry for project-specific additions. Strictness:

- `enum_mapping` / `relation_remap` throw on unmapped legacy values (no silent defaults);
- `uuid_preserve` validates UUID shape; `timestamp_normalize` converts to UTC;
- `utf8_text` throws on invalid UTF-8 bytes — **no lossy OEM/ANSI conversion exists anywhere in the engine**;
- `auth_identity_transform` refuses unrecognized password-hash formats.

The UTF-8 regression suite round-trips the exact Arabic strings `العربية`,
`القاهرة`, `الإسكندرية`, `إدارة الشحنات`, `وصلة` through
source → export → transform → target → JSON byte-exact (sqlite engine test +
real-PostgreSQL dogfood).

## Templates (24J.5–24J.10)

- **Auth template**: UUID preservation, verbatim bcrypt/argon2 carry (never
  re-hashed), profile linkage validator, hash strategy source-analyzed.
- **Storage template**: bucket/object inventory, manifest-based checksum
  validation, path preservation, URL rewrite transform, missing-object handling.
- **RLS template**: inventory → classify → candidate Laravel Policy/ Gate
  mapping + required ALLOW/DENY test matrix — never auto-applied.
- **RPC classification**: keep_postgresql / laravel_service / server_function
  / queue_job / needs_review.
- **Edge function classification**: internal logic / provider integration /
  webhook / scheduled job / server function / obsolete → implementation checklist.
- **`supabase-standard-migration`**: the reusable phase template (auth →
  enums → tables by FK order → views → RLS review → RPC → edge → storage →
  realtime → cron) derived from prior conversion experience without any
  project-specific domain semantics.

## Guarantees

Resume (completed items never re-run), clean rerun (idempotent inserts +
disposable reset), cancel-safe execution (cancel honored between items),
deterministic clean rehearsal (double run + comparison), and full audit trail.
