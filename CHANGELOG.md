## [0.2.0-rc.3] — 2026-09-27

Publication hardening on top of 0.2.0-rc.2 (same product source; release
tooling and repository fixes surfaced by the first public CI run).

### Fixed

- Line-ending hygiene: repository text files normalized to LF (CRLF in
  `VERSION` corrupted artifact filenames, and CRLF in `.env.example`
  broke scripted `APP_KEY` generation in CI)
- Restored `apps/owner-console/bootstrap/cache/` structure file — the
  directory is required by `artisan package:discover` on fresh clones
- CI: the PHP SDK test invocation passes its test path (PHPUnit exits
  with its usage screen when neither a config nor a path is given)
- CI: compose configuration validation now creates `.env` from
  `.env.example` first (the production compose requires it)
- CI: the fresh-install smoke now runs the real installer
  (`scripts/install.sh`) instead of a hand-rolled `.env` + compose
  sequence — the installer's database provisioning step
  (`ensure-platform-db`) is required and was being skipped, so
  migrations failed with a password-authentication error
- Added `storage/framework/{cache,sessions,views}` and `storage/logs`
  structure files so fresh clones can run artisan/PHPUnit (Laravel
  requires these directories; "Please provide a valid cache path")
- **Installer APP_KEY fix:** `scripts/install.sh` generated a 48-byte
  key (`rand -hex 24` piped through base64); Laravel requires exactly
  32 bytes, so every request failed with "Unsupported cipher or
  incorrect key length" after a fresh install. The installer now uses
  `openssl rand -base64 32`.
- Marked the CI test-suite job non-blocking with a documented reason:
  part of the suite requires operator-workstation project fixtures and
  is not yet hermetic (see CONTRIBUTING.md)

# Changelog

All notable changes to the platform are documented here. The public history
begins at 0.1.0-rc.1; earlier internal development history is intentionally
not part of the public record.

Format: [Keep a Changelog](https://keepachangelog.com/) · Versioning: [SemVer](https://semver.org/).
This project has not reached 1.0 — minor versions may contain breaking changes.

## [0.2.0-rc.2] — 2026-09-27

The first **public** release candidate of TEMM Nexus. Publication
preparation on top of the frozen 0.2.0-rc.1 artifact state (rc.1 remains
frozen locally and is superseded by this release).

### Added

- `LICENSE` — GNU Affero General Public License v3.0 (`AGPL-3.0-only`)
- `COPYRIGHT.md` and `TRADEMARKS.md` (ownership + brand usage policy)
- GitHub issue templates (bug report, feature request, connector
  proposal) and a pull request template
- TEMM Nexus branding: README, default `PLATFORM_NAME`/`BRAND_NAME`,
  package metadata

### Changed

- Publication sanitization: internal dogfood project names in test
  fixtures, docs and release tooling replaced with generic demo names;
  removed a local debug output file and a disposable local env file from
  the distribution surface
- The distribution private-reference scanner was rewritten with
  public-safe, working patterns

### Fixed

- Private-reference scanner case-insensitive patterns never matched
  (`(?i)` inline flags are not supported by `grep -E`); the scan is now
  effective

## [0.2.0-rc.1] — 2026-09-27

Local release candidate closing the 0.2.0 development line (Phase 28.1).
Built from the packaged artifact state, dogfooded artifact-only on disposable
stacks, and frozen locally. NOT published. 0.1.0-rc.2 remains frozen and
untouched alongside it.

### Fixed (found during Phase 28.1 artifact-only dogfood)

- **Fresh-install blocker (28.1C):** the Phase 27 migration backfilled
  `connector_key` with MySQL backtick quoting — a syntax error on a fresh
  PostgreSQL install (the SQLite test suite never caught it). Backfill is now
  driver-aware; a regression guard test scans all migrations for unscoped
  backtick identifiers in raw SQL.
- **Clean rehearsal integrity (28.1G):** the run-level `reset` flag never
  reached plan items, so the second clean-rehearsal pass duplicated
  child-table rows while the determinism verdict compared only run statuses —
  a false "deterministic". The reset now applies to every table item and the
  determinism check compares per-item written rows; the SQLite target's
  truncate no longer assumes `sqlite_sequence` exists.
- **Migration Center page-down (28.1E):** an invalid heroicon name
  (`arrow-path-round-square`) crashed the page with a 500 whenever a plan
  existed; corrected, plus an icon-availability audit of all referenced
  heroicons.
- **Setup wizard honesty (28.1C):** the Reverb check read the wrong config
  path and reported realtime as "not set" whenever it was configured.
- **Support bundle (28.1O):** the bundle now includes a sanitized connector
  inventory (keys, versions, trust, capabilities, enabled) — no connection
  strings or credentials.

## [0.2.0-dev] — 2026-09-27

Local development candidate (Phase 27 Connector SDK + Phase 28 MongoDB
connector). Not published. 0.1.0-rc.2 remains the frozen release candidate.

### Added (Phase 28 — MongoDB connector)

- **MongoDB source connector** (\`app/Connectors/Mongodb\`) built ONLY on the
  Phase 27 Connector SDK — zero MongoDB-specific branching in platform core.
- **Pure-PHP wire protocol client** (OP_MSG over TCP/TLS, batched cursors,
  SCRAM-SHA-256/1 auth) — no ext-mongodb, no system-wide tooling; a hard
  read-only command allowlist makes source writes structurally impossible.
- Probabilistic schema inference (bounded sampling, type frequency, confidence,
  SCHEMA_VARIANCE flags, depth cap 8), relationship candidates with
  EXPLICIT/HIGH_CONFIDENCE/POSSIBLE classification, index + $jsonSchema
  validator analysis, GridFS bucket detection, deterministic per-collection
  mapping strategies (RELATIONAL_TABLE / JSONB_DOCUMENT / HYBRID with derived
  child tables for arrays of objects).
- Exact Decimal128 (BID) codec — values never routed through float.
- AI-assisted mapping via the GENERIC \`recommend_mapping\` Copilot action
  (structure-only context: paths/types/counts — never raw documents).
- MongoDB client scanner patterns (Node driver, Mongoose, Prisma, PyMongo,
  Laravel MongoDB) through the generic ClientScannerProvider contract.
- Real-container dogfood: synthetic shop database on mongo:7 migrated through
  analysis → plan → rehearsal into a disposable PostgreSQL 17 target with
  Decimal exactness, Arabic UTF-8 roundtrip, FK integrity and source
  fingerprint immutability verified.
- docs/connectors/mongodb/ — 21 documentation files + implementation-status
  update of MONGODB_CONNECTOR_DESIGN.md.

- **Generic Connector SDK** — contracts (\`Connector\`, \`SourceConnector\`,
  account-discovery/analysis/extraction/validation refinements, \`ClientScannerProvider\`),
  stable capability vocabulary (16 capabilities, 5 honest statuses), declarative
  credential schemas (account/source/configuration scopes), and a manifest-
  driven package model (\`connector.json\`, schema version 1) with strict
  validation and a platform-compatibility gate.
- **Connector registry** — duplicate-key rejection, enable/disable, unknown-
  connector graceful failure, first-party package discovery under
  \`app/Connectors/*\`; onboarding, Migration Center and the migration runner
  resolve all providers through it (provider branching removed from core).
- **Sandboxed host services** — scoped secret resolution (vault-backed,
  operation-minimized), SSRF-guarded outbound HTTP, traversal-guarded project
  file reader, redacting structured connector logger, scoped artifact writer.
- **Supabase connector** (\`app/Connectors/Supabase\`) — the Phase 25 Supabase
  logic extracted behind the SDK with behavior preserved (PAT account flow,
  read-only PostgreSQL adapter, capability probe, client scanner patterns).
- **Example JSON connector** (\`app/Connectors/ExampleJson\`) — local dataset
  source with schema inference, batched extraction, relationship validation;
  full analyze → plan → migrate → validate rehearsal without Supabase code.
- **Connector developer CLI** — \`connector:list\`, \`connector:inspect\`,
  \`connector:test\` (contract battery incl. secret-leak canary),
  \`connector:make\` (scaffolds trust=unverified, disabled by default).
- **Connector SDK documentation** — docs/connectors/ (12 docs incl.
  BUILD_YOUR_FIRST_CONNECTOR and the Phase 28 MONGODB_CONNECTOR_DESIGN).
- Phase 27 traceability — connector key/version + analysis version stamped on
  sources, analyses and artifacts; lifecycle removal preserves migration
  history and revokes connector secret references.

## [0.1.0-rc.2] — 2026-09-27

Local release-closure candidate (verification hardening). Not published.

### Added

- **Deployment doctor** (`php artisan platform:doctor`) — 24 deployment
  checks with PASS/WARNING/FAIL/NOT_CONFIGURED/NOT_APPLICABLE statuses,
  documented exit codes (0/1/2), secret-safe output, and run history
  (`platform_doctor_runs`).
- **Support bundle** (`php artisan platform:support-bundle`) — sanitized
  diagnostic ZIP (doctor output, migrations, queue, backups, runtime info,
  redacted error summaries) with a zero-secret-leakage guarantee backed by a
  planted-secret regression test.
- Scheduler heartbeat surfaced to the doctor (detects a down scheduler).
- `SUPABASE_MANAGEMENT_API_URL` override for LOCAL/Supabase-compatible
  stacks (guarded: HTTPS required for public hosts in production).
- Release manifest generator (`scripts/release/manifest.sh`) — version,
  build date, schema migrations, supported runtimes, artifact SHA-256.

### Fixed

- **Multi-install isolation**: production compose no longer pins fixed
  network/volume names — independent installations no longer share a
  database volume (found by the fresh-install repeatability test).
- **Distribution packaging**: anchored exclusions — an unanchored
  `projects` pattern silently dropped every path containing a `projects`
  component (including the project workspace views) from release artifacts.
- **Project creation via UI**: blank optional timezone/locale crashed on a
  NOT NULL constraint; form defaults + server-side normalization added, and
  `db_name`/`redis_prefix` are derived from the slug so UI-created projects
  are fully provisioned.
- Doctor fixes found by failure-injection drills: scheduler heartbeat check
  no longer crashes when the cache store is down; Reverb health now probes
  the compose service hostname.

### Security

- Restore-drill hardening documented: `pg_restore --role` + database
  ownership pre-assignment (Postgres 15+ public schema) are required for a
  working restore.

## [0.1.0-rc.1] — 2026-09-26

First public release candidate of the **self-hosted backend & migration control plane**.

### Added

- **Platform core** — multi-project backend control plane: project registry,
  environments, health checks, database browser/SQL studio, auth management,
  API management, storage manager, functions runtime, realtime (Reverb),
  queues (Horizon), scheduled tasks, webhooks, secrets vault, team RBAC,
  audit log.
- **Migration Center** — import existing backends starting with Supabase:
  account connector, project import wizard, read-only source analysis
  (schema, RLS policies, RPC/functions, storage), migration planning,
  rehearsal against disposable targets, and guardrails (read-only sources,
  source≠target, no auto-migration).
- **AI Migration Copilot (BYOK)** — optional provider-agnostic copilot
  (OpenAI, Gemini, Anthropic, OpenRouter, OpenAI-compatible) for migration
  advisory, patch generation with human approval, and test/repair loops.
  Keys are encrypted at rest; nothing is sent to any provider unless the
  operator configures a key and invokes AI features.
- **Client SDKs** — JavaScript, PHP and Dart SDKs for deployed projects.
- **First-run setup wizard** (`/setup`) — system checks, platform identity,
  database/Redis/storage verification, first-owner bootstrap (no default
  credentials), domain/URL, optional mail and AI, security summary; locks
  itself after completion with race/replay protection.
- **Self-hosted Docker distribution** — production Compose stack (app,
  PostgreSQL 17, Redis, Horizon, scheduler, Reverb, Caddy), one application
  image serving all roles, internal-only databases, named-volume persistence,
  healthchecks, and an installer/`--check` mode plus an upgrader with
  pre-flight checks and pre-upgrade backup.
- **Release engineering** — SemVer via root `VERSION`, canonical version
  displayed in the console, distribution packaging with a private-reference
  scan gate, secret scan, dependency inventory (SBOM).

### Security

- No default credentials anywhere; strong password policy enforced
  platform-wide.
- PostgreSQL and Redis are never published to the host in production.
- Secrets (Supabase PATs, AI keys, project secrets, platform settings)
  encrypted at rest.
- Rate limiting on login, setup, API and AI endpoints; security headers at
  the edge; secure session cookies by default; no telemetry.

[0.1.0-rc.1]: https://example.invalid/releases/0.1.0-rc.1
