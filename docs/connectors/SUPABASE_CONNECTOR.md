# Supabase connector

The `supabase` connector package (`app/Connectors/Supabase/`, stable key
`supabase`, trust `first_party`, import flow `supabase-account`). It wraps the
Phase 25 Supabase import behind the Connector SDK with unchanged behavior —
everything Supabase-specific lives in this package, none of it in the core.

## Package contents

| File | Role |
|------|------|
| `connector.json` | manifest (13 capabilities, 4 permissions, `platform_requirement >=0.1.0`) |
| `SupabaseConnector.php` | implements `SourceConnector`, `AccountDiscoveryConnector`, `AnalyzableSourceConnector`, `ExtractableSourceConnector`, `ValidatableSourceConnector`, `ClientScannerProvider` |
| `SupabaseSourceAdapter.php` | the Phase 24 read-only PostgreSQL adapter |
| `SupabaseAccountService.php` | PAT storage + management API discovery (`api.supabase.com`) |
| `SourceCapabilityService.php` | Phase 25 channel matrix (delegates live probe to the generic `ConnectorCapabilityProbe`) |
| `SourceWriteGuard.php` | application-level SQL verb guard |
| `SupabaseClientScanner.php` | supabase-js/dart repo scanning patterns |

## Capabilities (13)

`account_discovery`, `project_discovery`, `database_metadata`,
`data_extraction`, `auth_metadata`, `storage_metadata`, `function_metadata`,
`policy_metadata`, `realtime_metadata`, `schedule_metadata`, `client_scan`,
`read_only_enforcement`, `source_fingerprint`.

Not declared: `storage_content`, `incremental_export`, `resume`
(`supportsResume()` is `false` — offset-batched re-runs, no persisted resume
tokens). Statuses are dynamic: a PAT unlocks discovery, `password`+`host`
unlock the database-metadata family (see CONNECTOR_CAPABILITIES.md).

## Credential fields

| Key | Scope | Secret | Unlocks | Notes |
|-----|-------|--------|---------|-------|
| `pat` | account | yes | `project_discovery` | Supabase management API token; encrypted at rest in `ExternalAccountConnection`; discovery ONLY |
| `password` | source | yes | `database_metadata` | stored in the project vault via `secret_refs['password']`; session forced read-only |
| `host` | configuration | no | `database_metadata` | default `127.0.0.1` |
| `port` | configuration | no | — | default `5432` |
| `database` | configuration | no | — | default `postgres` |
| `username` | configuration | no | — | default `postgres` |
| `schema` | configuration | no | — | default `public` |

## Read-only guarantee

Three independent layers:

1. **Session-level** — `connect()` runs `SET default_transaction_read_only = on`
   and `testConnection` verifies `SHOW transaction_read_only` returns `on`;
   a session that does not enforce read-only is classified `UNKNOWN_ERROR`.
2. **Transaction-level** — every inventory/count/stream operation runs inside
   `BEGIN TRANSACTION READ ONLY … COMMIT/ROLLBACK`
   (`SupabaseSourceAdapter::transactionReadonly`).
3. **Credential-layer** — the password comes ONLY from the project vault via
   `secret_refs` (never from the connection record), and the platform expects
   a READ-ONLY database credential from the operator.

Application-level complement: `SourceWriteGuard::assertReadOnly()` strips
comments/string literals then blocks write verbs (`INSERT`, `UPDATE`, `DELETE`,
`MERGE`, `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, `GRANT`, `REVOKE`) and
executive statements (`CALL`, `DO`, `VACUUM`, `REINDEX`, `LISTEN`, `NOTIFY`,
`COPY`) on any SQL destined for a source connection.

## Inventory coverage (normalized 27B.2)

Read from a PostgreSQL database carrying a Supabase-shaped schema (connections
point at local/staging snapshots or explicitly provided endpoints — production
Supabase is never contacted by the platform itself for DB access):

| Section | Source |
|---------|--------|
| `schemas` | `information_schema.schemata` (system schemas excluded) |
| `tables` | `pg_class` + columns (types incl. `varchar(n)`, arrays), primary keys, foreign keys, indexes, `rls_enabled`, row estimates (`pg_class.reltuples`) |
| `views` / `matviews` | `pg_class` relkind `v` / `m` |
| `enums` | `pg_enum` value lists |
| `functions` | `pg_proc`: security definer/invoker, language |
| `triggers` | `pg_trigger` (non-internal) with `pg_get_triggerdef` |
| `policies` | `pg_policies` (command, roles, using, with_check) |
| `extensions` | `pg_extension` |
| `auth` | presence, `auth.users` count, `auth.identities` count + distinct providers, hash strategy sampled from ONE non-null `encrypted_password` prefix (`bcrypt` / `argon2` / `unknown`) — never full hashes |
| `storage` | `storage.buckets` (name, public, file_size_limit) + per-bucket object counts and total bytes |
| `realtime` | `pg_publication_tables` for publication `supabase_realtime` |
| `cron` | `cron.job` (jobname, schedule, command) |
| `edge_functions` / `client_dependencies` | from the operator-imported manifest only (`source.connection['manifest'][…]`) — never from contacting the provider |

Extraction: `streamRows` batches with `ORDER BY 1 OFFSET/LIMIT`;
`streamAuthUsers` streams `auth.users` (id, email, encrypted_password,
raw_user_meta_data, created_at, last_sign_in_at) when the `auth` schema
exists; otherwise returns 0. Limitation: offset pagination re-reads from the
top — consistent with the honest `supportsResume() = false`.

## Account flow (supabase-account import flow)

1. **Connect account** — the operator submits a PAT;
   `SupabaseAccountService::connect()` stores it in `ExternalAccountConnection.secret_encrypted`
   (encrypted cast, status `unverified`), audit `SUPABASE_ACCOUNT_CONNECTED`.
2. **Test** — `GET {managementApi}/v1/projects` classification:
   200 `PASS`, 401 `INVALID_TOKEN`/`INVALID_CREDENTIAL`, 403
   `INSUFFICIENT_SCOPE`, 429 `RATE_LIMITED`, 5xx `PROVIDER_ERROR`,
   connection failure `NETWORK_ERROR`; audit `SUPABASE_CONNECTION_TESTED`.
3. **Discover** — same endpoint returns safe metadata only
   (`ref`, `name`, `organization`, `region`, `status`); unavailable fields stay
   null, never faked.
4. **Select project** — `selectProject()` creates a read-only `MigrationSource`
   (`type: supabase`, `secret_refs: []`, status `pending`) with management
   metadata (project ref, api_url, region), audit `SUPABASE_PROJECT_SELECTED`.
   Database credentials are supplied separately at analysis time (25C).

### Management API override

`SUPABASE_MANAGEMENT_API_URL` points discovery at a local/Supabase-compatible
stack (26.1B). Guard: in production, a plain-HTTP override is only allowed for
loopback/private/docker-internal hosts (single-label names, `localhost`,
private IP literals) — public hostnames must use HTTPS so the PAT never
crosses the public internet in cleartext.

## Client scanner patterns

`SupabaseClientScanner` (merged by the generic scanner, evidence hashed):

- URLs: `https://<ref>.supabase.co` / `.supabase.in` → risk
  `hardcoded_supabase_url`.
- Client init: `createClient(`, `createSupabaseClient(`, `Supabase.initialize(`.
- Secrets: `SERVICE_ROLE`, `SUPABASE_SERVICE`, long `anon_key` literals;
  env markers `SERVICE_ROLE`, `SUPABASE_SERVICE`; config dir `supabase/`.
- Dart idioms: `client.auth.signInWithPassword/signUp/signOut/...`,
  `.from('<table>')`, `.rpc('<fn>')`, `.functions.invoke('<name>')`,
  `.storage.from('<bucket>')`, `.channel('<name>')` / `.stream(executable:`.
- JS/TS/PHP idioms: the same call families via `supabase.auth.*`.

## Testing and dogfood

- Contract battery + SDK coverage: `tests/Feature/Phase27/` (classification
  via `Http::fake`, capability honesty, secret minimization).
- Phase 25 no-regression behavior: `tests/Feature/Phase25/SupabaseConnectorTest.php`.
- Live read-only dogfood against a real workspace: run locally against your
  own Supabase account (the live dogfood test class is intentionally not
  distributed) — account connect + discovery path exercised end to end.
