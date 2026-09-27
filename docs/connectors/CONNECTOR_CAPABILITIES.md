# Connector capabilities

How connectors declare what they can do (the stable 27C vocabulary) and how
the platform reports per-instance status honestly — support is never faked.

## The 16-key vocabulary

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

| Capability | `supabase` | `example-json` |
|------------|:----------:|:--------------:|
| `database_metadata`, `data_extraction`, `read_only_enforcement`, `source_fingerprint` | yes | yes |
| `account_discovery`, `project_discovery` | yes | — |
| `auth_metadata`, `storage_metadata`, `function_metadata`, `policy_metadata`, `realtime_metadata`, `schedule_metadata`, `client_scan` | yes | — |
| `storage_content`, `incremental_export`, `resume` | — | — |

Honest limitations: `resume` is declared by neither connector — extraction is
offset-batched, not token-based (`supportsResume()` returns `false`), and
`incremental_export` is future work (change-stream-style export is a Phase 28
design topic for MongoDB, see MONGODB_CONNECTOR_DESIGN.md).
