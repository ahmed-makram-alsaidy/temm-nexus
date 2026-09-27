# Migration Security

Phase 24 tooling is privileged and **fails closed**. Security surfaces and
their tests:

## Source-side

- **Read-only source guarantee**: session-level `default_transaction_read_only`
  + explicit READ ONLY transactions (structural, not procedural). Tested via
  adapter behavior; dogfood sources are read-only by construction.
- **No credentials in records**: `migration_sources.connection` stores
  coordinates only; passwords resolve from the vault via secret-ref names.
  The import wizard rejects anything resembling a pasted password.
- **No provider calls**: edge functions / client dependencies come from
  imported manifests; nothing contacts Supabase platform APIs.

## Runner guardrails (fail closed)

- Production target → refused (`target_environment_type === 'production'`).
- Reset without explicitly disposable target → refused.
- Source == target (including sqlite path comparison) → refused.
- Environment type immutable → cannot be re-labelled to escape guards.
- SOURCE and TARGET are displayed per run and audited redacted
  (`redactedTarget` strips values; secret-ref NAMES survive for resolution).

## Backup / restore

- Restore drills restore into NEW `restore_drill_*` databases only; realpath
  traversal guard requires the dump under `/backups`; disposable DB dropped
  in `finally`; active DB never touched by web requests.

## SSRF

- `ClientSetupService::testConnection`: allowlist = the project's own API
  host; internal/metadata IP ranges and localhost/cloud-metadata names are
  refused before any request; only http(s) schemes. (Tested with the
  169.254.169.254 metadata target.)

## Secrets & leakage

- Encrypted at rest; listings/tests assert value absence; masking in UI;
  reveal audited; `leakScan` sweeps logs + audit metadata for accidental
  leakage. Audit metadata never contains secret values (checked by test).

## SQL / injection

- All engine identifiers validated by `qi()` (`^[a-zA-Z_][a-zA-Z0-9_]*$`) —
  no identifier interpolation without validation.
- Queries use prepared statements; no string-built user input.
- Project/environment scoping: every query is keyed by the `{record}`-bound
  project id — cross-project substitution resolves to a different record,
  never another project's data (isolation tests over HTTP).

## Artifacts

Migration reports/dumps live in private storage
(`storage/app/control-plane/migration-artifacts/`, gitignored) — never in
public web directories.
