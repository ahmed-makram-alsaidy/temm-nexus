# Connector capabilities

How connectors declare what they can do (the stable 27C vocabulary) and how
the platform reports per-instance status honestly — support is never faked.

## The 19-key vocabulary

`App\Services\ControlPlane\Connectors\ConnectorCapability` is the single
source of truth (`ConnectorCapability::ALL`, display order). The core platform
only knows this vocabulary — it never knows which provider supports what.

| Key | Label |
|-----|-------|
| `account_discovery` | Account discovery |
| `project_discovery` | Project discovery |
| `database_metadata` | Database metadata |
| `data_extraction` | Data extraction |
| `auth_metadata` | Auth metadata |
| `storage_metadata` | Storage metadata |
| `storage_content` | Storage content |
| `function_metadata` | Function metadata |
| `policy_metadata` | Policy (RLS) metadata |
| `realtime_metadata` | Realtime metadata |
| `schedule_metadata` | Scheduled jobs |
| `client_scan` | Client repository scan |
| `read_only_enforcement` | Read-only enforcement |
| `source_fingerprint` | Source fingerprint |
| `incremental_export` | Incremental export |
| `resume` | Resume support |
| `change_capture` | Change capture (CDC) |
| `consistent_snapshot` | Consistent snapshot |
| `checkpoint` | Checkpoint support |

## The 5 statuses

Per-instance capability status (27C.1) is one of:

| Status | Meaning |
|--------|---------|
| `SUPPORTED` | available with the current configuration |
| `SUPPORTED_WITH_CONFIGURATION` | the connector supports it, but the required credential is not resolvable yet — supply it to enable |
| `PARTIAL` | partially available |
| `NOT_SUPPORTED` | not supported by this connector (also used for undeclared capabilities) |
| `NOT_APPLICABLE` | not applicable to this source |

## Declaring capabilities

Capabilities appear in two places that must agree:

1. `connector.json` `capabilities` (validated against the vocabulary, ≤ 32).
2. `Connector::capabilityStatus(string $capability, array $resolvableFieldKeys): string`
   — the dynamic per-instance answer given which credential fields are
   currently resolvable.

`ConnectorCapabilityProbe::matrix($source)` builds the UI rows
(`capability`, `label`, `status`, `detail`) by combining the declared list
with `ScopedSecretResolver::resolvableFieldKeys()`. An undeclared capability
must return `NOT_SUPPORTED` — the contract-test battery
(`checkCapabilityStatusHonesty`) fails any connector that fakes support.

### Supabase mapping (27C.2)

`app/Connectors/Supabase/SupabaseConnector.php`:

| Capabilities | Status rule |
|--------------|-------------|
| `account_discovery`, `project_discovery` | `pat` resolvable → SUPPORTED, else SUPPORTED_WITH_CONFIGURATION |
| `database_metadata`, `data_extraction`, `auth_metadata`, `storage_metadata`, `function_metadata`, `policy_metadata`, `realtime_metadata`, `schedule_metadata` | `password` + `host` resolvable → SUPPORTED, else SUPPORTED_WITH_CONFIGURATION |
| `client_scan`, `read_only_enforcement`, `source_fingerprint` | always SUPPORTED |
| everything else (e.g. `incremental_export`, `resume`) | NOT_SUPPORTED |

A PAT therefore *unlocks project discovery*, and a database password unlocks
the database-metadata family — visible in the UI without contacting the
source (`ConnectorSdkTest::test_capability_model_is_generic_and_honest`).

## Dynamic capability probe (27C.2)

`ConnectorCapabilityProbe::probe($source)` performs a **live read-only
probe** that any source connector receives generically:

1. Resolve the connector through the registry; if `analysisRequires()` fields
   are not resolvable → `overall: NEEDS_CREDENTIAL`.
2. `connect()` → `inventory()` → `close()` on the source adapter.
3. Derive domains from the **normalized inventory** (no provider branching):

| Domain | PASS when | else |
|--------|-----------|------|
| `database` | ≥ 1 table | `PARTIAL` |
| `rls` | ≥ 1 policy or a table with `rls_enabled` | `PARTIAL` |
| `auth` | `auth.present` with `users_count > 0` | `PARTIAL` |
| `storage` | `storage.present` | `PARTIAL` |
| `functions` | ≥ 1 function | `PARTIAL` |
| `views` | ≥ 1 view or matview | `PARTIAL` |
| `extensions` | ≥ 1 extension | `PARTIAL` |

Result shape is compatible with the Phase 25 wizard
(`overall: PROBED|NEEDS_CREDENTIAL|FAILED` + `domains`); the probe persists
the domain map on `MigrationSource.capabilities` and marks the source `ready`.
Nothing is claimed COMPLETE without evidence — PASS/PARTIAL only.

## Which shipped connectors declare what

All six shipped connectors are `first_party` (see `connector.json` inside
each `app/Connectors/<Key>/` directory — that manifest is the machine
source; this table is the human view).

| Capability | `supabase` | `mongodb` | `firebase` | `postgres` | `mysql` | `example-json` |
|------------|:----------:|:---------:|:----------:|:----------:|:-------:|:--------------:|
| `account_discovery` | yes | — | — | — | — | — |
| `project_discovery` | yes | — | — | — | — | — |
| `database_metadata` | yes | yes | yes | yes | yes | yes |
| `data_extraction` | yes | yes | yes | yes | yes | yes |
| `auth_metadata` | yes | — | yes | — | — | — |
| `storage_metadata` | yes | — | yes | — | — | — |
| `function_metadata` | yes | — | yes | yes | yes | — |
| `policy_metadata` | yes | — | — | yes | — | — |
| `realtime_metadata` | yes | — | — | — | — | — |
| `schedule_metadata` | yes | — | — | — | yes | — |
| `client_scan` | yes | yes | yes | — | — | — |
| `read_only_enforcement` | yes | yes | yes | yes | yes | yes |
| `source_fingerprint` | yes | yes | yes | yes | yes | yes |
| `incremental_export` | — | — | — | — | — | — |
| `resume` | — | yes | yes | yes | yes | — |
| `change_capture` | — | yes | — | yes | yes | — |
| `consistent_snapshot` | — | — | — | yes | — | — |
| `checkpoint` | — | yes | — | yes | yes | — |

`example-json` is the **reference connector** (Phase 27 SDK contract
example) — it exercises the smallest honest surface, not a migration
product. `incremental_export` is declared by no connector: watermark
incremental export exists as a Phase 32 mechanism, but log-based CDC
(`change_capture` + `checkpoint`) is the supported incremental path since
Phase 35.6 (`docs/connectors/REAL_CDC_RUNBOOK.md`).

## Real CDC support matrix (Phase 35.6)

Connector **analysis/migration support** and **log-based CDC support** are
different claims; both are stated per provider. No capability below is
marked supported from unit tests alone — each is backed by the live
verification in `docs/connectors/PHASE_35_6_REAL_CDC_REPORT.md` and the
Phase 35.5 live connector verification.

| Provider | Connector | Migration/analysis | Log-based CDC | Mechanism |
|----------|-----------|--------------------|---------------|-----------|
| Supabase | `supabase` | SUPPORTED | not declared on the `supabase` connector; a Supabase database is standard PostgreSQL and may be captured with the generic `postgres` connector when its replication prerequisites are met | — |
| MongoDB | `mongodb` | SUPPORTED | SUPPORTED (replica set required) | Change Streams + server resume tokens |
| Firebase | `firebase` | SUPPORTED | **DEFERRED** | — |
| Generic PostgreSQL | `postgres` | SUPPORTED | SUPPORTED | logical WAL decoding (`pgoutput`) + durable LSN |
| MySQL 8 | `mysql` | SUPPORTED | SUPPORTED | ROW binlog capture, durable file+position (GTID evidence) |
| MariaDB | `mysql` | **PARTIAL** | **PARTIAL** — not separately proven on a live MariaDB server | same connector, unverified |
| Example JSON | `example-json` | reference connector | NOT_SUPPORTED | — |
