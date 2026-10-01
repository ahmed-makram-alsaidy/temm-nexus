# Release Notes — Platform v0.3.0-rc.1

**Release date:** 2026-10-01
**Status:** PRE-RELEASE — release candidate (not stable)
**Minimum upgrade-from version:** 0.1.0 (see docs/open-source/UPGRADE_ROLLBACK.md)

## What's new

- **Real log-based CDC (change data capture)** for PostgreSQL (logical WAL
  decoding / `pgoutput`), MySQL 8 (row-based binlog capture) and MongoDB
  (change streams) — provider-native positions (durable LSN / binlog
  file+position with GTID evidence / server resume tokens), HMAC-signed
  tamper-safe checkpoints, restart/resume, at-least-once delivery with
  idempotent apply, DELETE propagation, transaction boundaries preserved,
  schema-drift pause with operator remediation, real lag telemetry and
  cutover gates, and the operator freeze-boundary final sync
  (`DATA_READY_FOR_CUTOVER`). Operator entry point: `migration:cdc-capture`.
- **Firebase connector** — read-only Firestore analysis, Auth inventory
  (no password material), Storage, Cloud Functions and client-scan
  inventories.
- **Generic PostgreSQL connector** — import any standard PostgreSQL
  database (not just Supabase): pg_catalog-native inspection, exact type
  preservation, enforced read-only sessions.
- **MySQL/MariaDB connector** — unsigned-safe type widening,
  AUTO_INCREMENT state preservation, charset analysis.
- **Cutover Center** — project-scoped control room with honest gate states,
  ordered cutover plans, explicit approvals, rollback plan with expiry.
  The platform never executes DNS/endpoint/production changes.
- **AI client-code migration** — deterministic conversion planning for
  supabase-js/dart and firebase-js/dart, approval-gated patch workspace,
  secret redaction in AI prompts.
- **Connector marketplace foundation** — package install lifecycle with
  checksum verification, trust vocabulary, malicious-package rejection.
- Live verification on disposable PostgreSQL 17 / MySQL 8.0 / MongoDB 7
  replica set: insert/update/delete, transactions, reader kill + resume,
  source restarts (binlog rotation included), tamper refusal, and event
  storms up to 155,000 events with zero loss and zero reconciliation
  delta (see `docs/connectors/PHASE_35_6_REAL_CDC_REPORT.md`).

## Changed

- Release candidate software: breaking changes may still land before
  stable 0.3.0.
- Migration capability is **low-downtime, not zero-downtime**: CDC
  capture shortens the window, but cutover remains an operator-controlled
  procedure with documented prerequisites.
- The hermetic release test suite is now separated from operator-fixture
  tests and runs as a BLOCKING CI job
  (`docs/TEST_CLASSIFICATION.md`).

## Fixed

- Production asset pipeline: frontend assets (Filament CSS/JS/fonts,
  Livewire) are generated in production builds — fresh installs no longer
  serve an unstyled console.
- Production image ships `pdo_mysql`; scheduler heartbeat age reported
  unsigned in Deployment Doctor; Pulse check daemon no longer starves the
  schedule loop; healthcheck 200 detection and installer dry-run banner
  fixed.
- Connector marketplace deny-reasons keep their actual error text under
  the message cap on deep install roots.
- `security-check.sh` self-match; auth-identity target-table collision
  handling in the migration engine.

## Security

- `league/commonmark` upgraded to 2.10.3 (security advisories), locked;
  `composer audit` reports 0 advisories for the shipped lockfile.
- Secret scan and distribution private-reference scan pass on the release
  tree and on the built artifact.

## Upgrade notes

- Read `docs/open-source/UPGRADE_ROLLBACK.md`; run the pre-upgrade backup
  (the upgrader does by default).
- CDC sources need provider-side configuration/permissions: PostgreSQL
  logical replication (WAL level, replication role, publication), MySQL
  `log_bin` + `binlog_format=ROW` + REPLICATION privileges, MongoDB
  replica set. See `docs/connectors/REAL_CDC_RUNBOOK.md`.
- Operator-gated test suites are now classified; the blocking release
  gate is `phpunit-release.xml` (see `docs/TEST_CLASSIFICATION.md`).

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
- No absolute zero-downtime guarantee: the final cutover is operator-
  controlled and brief application downtime is expected.
- Release-candidate software: expect breaking changes before stable 0.3.0.

## Verification

This release passed the following gates before publication:
- Hermetic blocking release suite: 426 tests / 2207
  assertions / 0 failures / 0 errors (see docs/TEST_CLASSIFICATION.md for
  the classification; operator-fixture suites are documented debt, not
  hidden).
- SDK suites (JS + PHP): pass.
- Secret scan + private-reference scan of the distribution artifact: 0
  findings.
- Artifact-only fresh installs (x2, isolated), frontend asset gate,
  connector registry gate: pass.
- Real CDC proven against the artifact for PostgreSQL WAL, MySQL binlog
  and MongoDB change streams: lost events 0, duplicate corruption 0,
  reconciliation delta 0.
- Backup/restore drill, upgrade drill from v0.2.0-rc.3 with failure
  detection, restart/reboot persistence: pass.
- Deployment Doctor on the artifact install: 0 mandatory failures.
- Fresh-install dogfood (clean state → setup → owner bootstrap → project)
  and restart persistence: pass.
