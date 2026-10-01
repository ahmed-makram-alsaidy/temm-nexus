# Phase 36 — 0.3.0-rc.1 Release Closure Report

**Status: READY_TO_PUBLISH_V0.3.0_RC1 → published as a GitHub PRE-RELEASE
(stable 0.3.0 intentionally NOT published).**

## Release

| | |
|---|---|
| Version | 0.3.0-rc.1 |
| Release branch | `release/0.3.0-rc.1` (from `develop/0.3.0` @ `a30b31c`) |
| Tag | `v0.3.0-rc.1` (annotated; dereferences to the release commit) |
| Artifact | `platform-0.3.0-rc.1-20261001-170557.tar.gz` (1063 entries) |
| SHA-256 | `102f1215ef66e87dc831aaf763c10c19780c6030353ca77548a374f2b5a0e010` (verified by sha256sum + openssl) |

## Old release integrity

`v0.2.0-rc.3`: tag object `2590c6ab`, dereferenced commit `945f0c3` =
`origin/main`. The Phase 35.6 "SHA ambiguity" is resolved: `2590c6ab` is
the annotated tag OBJECT; the historic commit is unchanged. Old artifact
untouched.

## Test classification (docs/TEST_CLASSIFICATION.md)

- **Hermetic blocking suite** (`phpunit-release.xml`): **426 tests,
  2207 assertions, 0 failures, 0 errors**, 4 env-guarded skips — run on
  the reference PHP 8.4 Linux container AND from a fresh checkout with
  dependencies installed from lockfiles (no reused vendor, no .env).
- **CI**: new BLOCKING `owner-console-release-suite` job green; broad
  suite stays non-blocking with the classification doc as the documented
  reason (operator fixtures: gate-a/gate-b, demo database, live Redis —
  they error + cascade a transaction state when fixtures are absent:
  479 errors locally vs the 86/30 operator-workstation baseline; all
  cascade, zero product regressions found among them).
- SDK suites (JS + PHP), script tests, container build, fresh-install
  smoke: green on public CI.

## Artifact gates

- Fresh artifact-only install #1 (default ports) — PASS: compose config,
  PostgreSQL 17, Redis, app, Horizon, scheduler, Reverb, Caddy healthy;
  migrations; setup wizard; first admin; setup lock.
- Fresh artifact-only install #2 (`second-plane`, ports 8080/8443, distinct
  credentials) — PASS; both stacks ran concurrently with zero shared
  container/volume/network/database identities.
- Frontend asset gate — PASS: Filament CSS/JS, Inter fonts, Livewire JS
  (nonce'd route) all 200 with correct MIME; no asset 404s.
- Connector registry — PASS: all six `first_party` connectors
  (supabase, mongodb, firebase, postgres, mysql, example-json).
- Deployment Doctor — 16 PASS / 0 FAIL (installs), 18 PASS / 0 WARN /
  0 FAIL (Azure).
- Backup/restore drill — PASS (pg_dump 288 KB; restore into scratch DB;
  users=1, projects=9 preserved; ownership semantics per
  docs/open-source/BACKUP_RESTORE.md).
- Support bundle — PASS: planted vault canary + real vault/admin/env
  secrets absent from the bundle (names-only env export).

## Real CDC proven against the artifact (Step 18)

Disposable PostgreSQL 17 / MySQL 8.0 / MongoDB 7 replica set, harness =
Phase 35.6 operator scripts, running inside the artifact's app container:

| Provider | I/U/D + tx | Backlog / resume | Lost | Dup corruption | Reconcile delta | Cutover gate |
|---|---|---|---|---|---|---|
| PostgreSQL (WAL `pgoutput`, durable LSN) | 9 events | 7000 events > 5k cycle cap → interrupt → resume | 0 | 0 | 0 | PASS — DATA_READY_FOR_CUTOVER |
| MySQL (ROW binlog, file+pos, GTID evidence) | 7 events | 6000+3000 events → interrupt → resume | 0 | 0 | 0 | PASS — DATA_READY_FOR_CUTOVER |
| MongoDB (change streams, resume tokens) | 3 events (incl. nested update) | 6000 inserts (5k cap) → resume; tamper test | 0 | 0 | 0 | PASS — DATA_READY_FOR_CUTOVER |

Tamper refusal: edited resume token REFUSED (`CdcCheckpointTampered`).

**Fix found & fixed by this gate** (`8d03151`): Mongo CDC rows are now
projected through the plan's `sanitized_field_map` — previously the
default projection dropped flattened nested columns, so a NOT NULL target
column paused the run at the first streamed event. CDC rows and snapshot
rows are structurally identical now.

## Upgrade drills

- v0.2.0-rc.3 → v0.3.0-rc.1 (isolated `third-plane` stack): upgrader
  backup → build → migrate → restart → verify. First admin (1), setup
  lock, initialization, settings preserved; Doctor 15 PASS / 0 FAIL.
- Failure injection: a deliberately-throwing migration makes the upgrader
  FAIL LOUDLY (exit 1, migration shows FAIL, stack stays healthy,
  pre-upgrade backups intact).
- App rollback to rc.3 source: verified (image rebuilt, login 200).
  Database rollback remains **RESTORE_REQUIRED** (forward-only
  migrations) — documented, never faked.

## Security

composer audit 0 advisories · secret scan PASS · private-reference scan
PASS (repo + artifact) · no unintended emails/paths/names in the release
tree · support bundle redaction verified.

## Public CI & publication

- CI run 36876139071 green (blocking jobs). One reproducibility fix
  (`b0ee684`): edge JS MIME check accepts `text/javascript` (current
  caddy 2.x RFC 9239 name) alongside `application/javascript`.
- GitHub PRE-RELEASE `v0.3.0-rc.1` published with artifact, SHA256SUMS,
  manifest, SBOM reference. Downloaded-back asset re-hashed and compared
  — byte-identical.

## Azure live proof (temm-test.ahmed-alsaidy.online)

Disposable Azure D2as_v6 VM upgraded 0.3.0-dev → rc.1 artifact via the
real upgrader. HTTPS, login, dashboard assets, Doctor 18/0/0, bounded
CDC smoke on all three providers (deltas 0, DATA_READY_FOR_CUTOVER ×3),
and full VM **reboot persistence** (all services auto-recovered, domain
200). No production customer systems touched.

## Environment notes (transparency)

- The operator workstation's Docker Desktop data disk (E:) was full, which
  disabled the local engine. Local gates were unblocked by relocating
  Docker's NEW data disk to C: (settings backup at
  `settings-store.json.phase36-backup`); the old 75 GB disk on E: is left
  untouched for the owner to prune/compact (48 GB containerd image cache
  is re-pullable; docker VOLUMES inside are user data — not touched).
- dev-php84 Dockerfile gained `gd` (prod-image parity; gd is BLOCKING in
  the first-run SystemCheck) — the stale image explained SetupWizard
  failures outside CI.
- `phpunit-release.xml` is the release gate; the broad `phpunit.xml`
  remains the operator-workstation suite.
