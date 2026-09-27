# Example JSON connector

The `example-json` connector (`app/Connectors/ExampleJson/`, trust
`first_party`, import flow `credentials-form`) — the non-Supabase proof that
the Connector SDK is provider-agnostic. A local JSON dataset directory flows
through the full Migration Center pipeline (analyze → plan → migrate →
validate) with **no Supabase code participating** (27N.3).

## Dataset contract

| Rule | Detail |
|------|--------|
| Layout | one directory; one `<collection>.json` file per collection (e.g. `users.json`) |
| Content | each file is a **JSON array of objects**; a bare JSON object is accepted as a single-record collection; non-object entries are dropped |
| File caps | `config('connectors.limits.max_dataset_files')` = 50 files, `max_dataset_file_kb` = 10 240 (10 MB) per file — enforced by `ProjectScopedFileReader` |
| Path access | reads go through `ProjectScopedFileReader` (traversal, extension and size guards; the connector declares `filesystem.dataset.read`) |
| Invalid JSON | fails `connect()` with a clear `InvalidArgumentException` naming the file |

Shipped sample datasets: `app/Connectors/ExampleJson/datasets/`
(`users.json`, `orders.json`, `order_items.json`).

## Schema inference (27N.2)

`ExampleJsonSourceAdapter` infers a schema by scanning every record of a
collection. Inferred scalars are normalized to the engine's PostgreSQL-style
type vocabulary — the same vocabulary every source and target adapter speaks,
so plans and target schema builds work unchanged.

| JSON shape | Inferred type |
|------------|---------------|
| integer | `int8` |
| float | `float8` |
| bool | `boolean` |
| ISO-8601 datetime string (`2026-01-05T10:00:00Z` style) | `timestamptz` |
| UUID string | `uuid` |
| array / object | `jsonb` |
| any other string / mixed types | `text` |

Inference details:

- **Conflicts**: `int` + `float` in one column → `float`; any other mixed
  pair widens to `string` (→ `text`).
- **Nullability**: a column is nullable when any record has `null` for it OR
  any record is missing the key entirely.
- **Primary key**: `['id']` when every record has a non-null `id`, else `[]`.
- **Foreign keys**: every `<column>_id` column maps to collection
  `<singular>.id` when that collection exists
  (`user_id` → `users.id`, `order_id` → `orders.id`). Pluralization handles
  `-y → -ies` (`category_id` → `categories`) and `s/x/z/ch/sh → es`.
- **Row estimate**: exact record count.

Example result for the fixture: `orders` gets columns
`id:int8, user_id:int8, total:float8, currency:text, status:text,
created_at:timestamptz`, primary key `['id']`, FK `user_id → users.id`,
12 rows.

## Extraction

- Records are sorted deterministically by `id` (numeric compare when all ids
  are numeric, string compare otherwise) for stable validation; unsorted when
  there is no usable id.
- `streamRows(schema, table, columns, callback, batchSize)` chunks with
  `array_chunk`, invokes the callback per row (never one giant payload), and
  applies column projection when a column list is given.
- `countRows()` returns the exact collection size; `streamAuthUsers()` returns
  0 (no auth domain — the generic default).
- `supportsResume()` is honestly `false`: in-memory batching, no cursors.

## Validation artifacts

`ExampleJsonConnector::validateSource()` returns
`kind: connector_validation` with:

- `row_counts` — exact per-table counts;
- `foreign_key_issues` — for each inferred FK, orphan rows are detected by
  streaming the referenced collection's ids and counting child rows whose
  non-null value has no parent;
- `source_fingerprint` — deterministic fingerprint of the inventory.

Verified in `ExampleConnectorEndToEndTest::test_connector_validation_artifacts_report_relationship_integrity`
(deleting a user row produces FK violations on the next validation).

## End-to-end flow through the Migration Center

`tests/Feature/Phase27/ExampleConnectorEndToEndTest.php` runs the full
pipeline on the fixture dataset (12 users → 24 orders → 36 order_items):

1. **Create profile** — `createSourceProfile()` stores `dataset_path` in
   `MigrationSource.connection` (configuration scope; no secrets), `read_only: true`.
2. **Analyze** — `MigrationCenterService::analyze()` resolves the adapter
   through `ConnectorRegistry` (no provider branch), builds the normalized
   analysis, counts `tables: 3`, `auth: 0`.
3. **Classify** — the compatibility engine + risk detector run on the same
   normalized items as for Supabase.
4. **Plan** — `generatePlan()` orders tables by FK dependency: `users` and
   `orders` in earlier stages than `order_items`.
5. **Run** — `MigrationRunManager::execute()` on a disposable rehearsal
   target (`driver: sqlite` — PostgreSQL targets work the same way):
   all 72 rows land, FK integrity holds (no orphaned `orders.user_id`).
   `dry_run` mode never creates the target database.
6. **Validate** — the generic validator suite runs over the plan; connector
   validation artifacts add the counts/FK report above.

## Credential surface

Single field `dataset_path` (type `file`, scope `configuration`, required,
unlocks `database_metadata`). Capability statuses: `database_metadata` and
`data_extraction` are SUPPORTED once `dataset_path` resolves, otherwise
`SUPPORTED_WITH_CONFIGURATION`; `read_only_enforcement` and
`source_fingerprint` are always SUPPORTED; `auth_metadata` etc. are honestly
`NOT_SUPPORTED`.

## Why it matters

Phase 28's MongoDB connector follows this exact shape: a connector package
that produces the normalized 27B.2 inventory and plugs into the unchanged
engine (see MONGODB_CONNECTOR_DESIGN.md). The core never learned anything
Supabase-specific to make this work.
