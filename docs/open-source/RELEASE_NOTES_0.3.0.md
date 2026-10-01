# Release Notes — TEMM Nexus v0.3.0 (first stable)

**Release date:** 2026-10-01
**Status:** STABLE
**Minimum upgrade-from version:** 0.1.0 (see docs/open-source/UPGRADE_ROLLBACK.md)

TEMM Nexus is an open-source, self-hosted backend migration & control plane.
v0.3.0 is its first stable release.

## Major supported capabilities

- **Supabase migration** — read-only PostgreSQL snapshot analysis with auth,
  storage, functions, RLS policies, realtime and cron metadata.
- **Generic PostgreSQL migration** — import any standard PostgreSQL database
  (not just Supabase): pg_catalog-native inspection, exact type
  preservation, enforced read-only sessions.
- **MySQL migration** — unsigned-safe type widening, AUTO_INCREMENT state
  preservation, charset analysis.
- **MongoDB migration** — collection/document analysis and snapshot import.
- **Firebase migration** — read-only Firestore analysis, Auth inventory
  (no password material), Storage, Cloud Functions and client-scan
  inventories.
- **Real CDC (change data capture)** for low-downtime migrations:
  - PostgreSQL — logical WAL decoding (`pgoutput`), durable LSN positions;
  - MySQL 8 — row-based binlog capture, file+position resume with GTID
    evidence;
  - MongoDB — change streams with server resume tokens.
  HMAC-signed tamper-safe checkpoints, restart/resume, at-least-once
  delivery with idempotent apply, DELETE propagation, transaction
  boundaries preserved, schema-drift pause with operator remediation, real
  lag telemetry and cutover gates, and the operator freeze-boundary final
  sync (`DATA_READY_FOR_CUTOVER`). Operator entry point:
  `migration:cdc-capture`.
- **AI client-code migration** — deterministic conversion planning for
  supabase-js/dart and firebase-js/dart, approval-gated patch workspace,
  secret redaction in AI prompts.
- **Cutover Center** — project-scoped control room with honest gate states,
  ordered cutover plans, explicit approvals, rollback plan with expiry.
  The platform never executes DNS/endpoint/production changes.
- **Connector architecture & marketplace foundation** — first-party
  connector packages with strict manifest validation, package install
  lifecycle with checksum verification, trust vocabulary, malicious-package
  rejection.
- **Backup & restore** — Backup Center destinations/policies, platform +
  project database backups, documented restore drill and ownership
  semantics.
- **Self-hosted deployment** — hardened installer (`scripts/install.sh`),
  one-command upgrader (`scripts/upgrade.sh`) with automatic pre-upgrade
  backup, Caddy automatic edge refresh on upgrade, Deployment Doctor
  (`platform:doctor`), support bundles with redaction.
- **Owner Console** — Filament-based admin panel: projects, database
  browser, migration center, cutover center, backups, infrastructure
  health, audit log, team.

## Migration semantics — read this

Migration capability is **low-downtime, not zero-downtime**: CDC capture
shortens the window, but the final cutover remains an operator-controlled
procedure with brief application downtime expected. The platform never
claims a guaranteed zero-downtime switch and never executes production
endpoint changes by itself.

## Changed

- Version promotion from `v0.3.0-rc.3` — content identical to rc.3; the
  codebase passed the full stable release closure (below).

## Security

- `composer audit`: 0 advisories for the shipped lockfile.
- Secret scan and distribution private-reference scan pass on the release
  tree and on the built artifact.
- Support bundle redaction verified (no secrets, no .env values, no
  customer data).

## Known limitations

- **Firebase CDC is DEFERRED** — the Firebase connector migrates/analyzes
  but does not capture live changes.
- **MariaDB is PARTIAL** — the MySQL connector is only separately proven
  on MySQL 8.0; MariaDB servers are unverified.
- MongoDB multi-document transactions apply without cross-document
  atomicity (convergent, idempotent).
- MySQL resume is file+position (GTID set recorded as evidence); the
  GTID-dump packet of the bundled binlog library is unusable on MySQL 8.0.
- TRUNCATE (PostgreSQL) and Mongo `INVALIDATE` pause capture and require
  an operator decision; they are never guessed through.

## Verification (stable closure, 2026-10-01)

- Hermetic blocking suite (`phpunit-release.xml`): **427 tests / 2212
  assertions / 0 failures / 0 errors**, 4 env-guarded skips; JS SDK 31/0,
  PHP SDK 12/0.
- Real browser UI smoke against the live deployment: login, dashboard,
  projects, connector catalog, migration center, backups, infrastructure,
  logs/settings — zero 500s, zero failed assets.
- Live bounded CDC closure smoke on all three providers (disposable
  sources): INSERT/UPDATE/DELETE + multi-row transactions, SIGKILL of the
  capture reader mid-run, durable resume (LSN / file+position / resume
  token — the token survived a full VM reboot), reconciliation delta 0,
  lost 0, duplicate corruption 0; Cutover Center gates evaluated from real
  telemetry → `DATA_READY_FOR_CUTOVER` ×3.
- Backup + restore drill: fresh platform dump restored into a scratch
  database with ownership semantics; admin, setup lock, projects, CDC
  checkpoints preserved.
- Full Azure VM reboot: all containers auto-recovered healthy, HTTPS 200,
  Doctor 18 PASS / 0 FAIL, CDC resume from persisted position.
- Artifact: built from a clean checkout; secret scan, private-reference
  scan and unintended-content scan all clean; SHA-256 published and
  verified against the downloaded-back asset.
- Artifact-only fresh install + artifact CDC smoke on all three providers;
  rc.3 → stable upgrade drill with data preservation and automatic Caddy
  refresh.

## Upgrade notes

- Read `docs/open-source/UPGRADE_ROLLBACK.md`; the upgrader creates a
  pre-upgrade backup automatically.
- CDC sources need provider-side configuration/permissions: PostgreSQL
  logical replication (WAL level, replication role, publication), MySQL
  `log_bin` + `binlog_format=ROW` + REPLICATION privileges, MongoDB
  replica set. See `docs/connectors/REAL_CDC_RUNBOOK.md`.
