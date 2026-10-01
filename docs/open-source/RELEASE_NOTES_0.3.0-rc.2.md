# Release Notes — Platform v0.3.0-rc.2

**Release date:** 2026-10-01
**Status:** PRE-RELEASE — release candidate (not stable)
**Minimum upgrade-from version:** 0.1.0 (see docs/open-source/UPGRADE_ROLLBACK.md)

## What's new

This is a **release-closure fix** on top of the frozen v0.3.0-rc.1. The
rc.1 product surface (real log-based CDC for PostgreSQL/MySQL/MongoDB,
Firebase + generic PostgreSQL + MySQL connectors, Cutover Center, AI
client-code migration, marketplace foundation) is unchanged.

### Fixed

- **Upgrader: Caddy edge configuration is now reliably refreshed on
  upgrade.** `docker compose up -d` recreates a container only when its
  service definition changes; the content of a bind-mounted file is
  invisible to compose, and Caddy reads its Caddyfile once at container
  start. An upgrade that shipped a changed `Caddyfile.selfhost` (new
  routing, domain or headers) therefore left the OLD edge configuration
  active while the upgrade reported success — reproduced locally and hit
  live when upgrading the Azure test VM to rc.1 (404 responses until
  caddy was recreated by hand).
  The upgrader now fingerprints the Caddy-relevant inputs (the Caddyfile
  plus the `PRIMARY_DOMAIN`/`ACME_EMAIL`/`HTTP_PORT`/`HTTPS_PORT` env
  values it interpolates) and force-recreates **only caddy** when that
  fingerprint changed since the last applied upgrade; unchanged
  configuration is left running (no destructive recreation without
  reason) and the caddy TLS volumes are untouched. Fresh installs record
  the same fingerprint. A recreate failure aborts the upgrader
  non-zero — never silent.

### Changed

- Nothing else. rc.1 remains frozen; this release exists because the
  fixed behavior affects the supported upgrade path.

### Security

- No security-relevant changes since rc.1. `composer audit`: 0
  advisories; secret scan and distribution private-reference scan pass.

### Upgrade notes

- From rc.1: run `./scripts/upgrade.sh` as usual. On the first upgrade
  to rc.2 the upgrader recreates caddy once (no prior fingerprint
  exists) to guarantee the shipped Caddyfile is active — a brief edge
  restart, TLS certificates preserved in the caddy volumes.
- From earlier versions: upgrade to rc.1 first if you rely on the CDC
  surface (see rc.1 notes), or directly if you do not use CDC.

### Known limitations

- Unchanged from rc.1: Firebase CDC DEFERRED; MariaDB PARTIAL; MongoDB
  multi-document transactions applied without cross-document atomicity;
  low-downtime (not zero-downtime) cutover, operator-controlled.
- Release-candidate software: expect breaking changes before stable
  0.3.0.

## Verification

- Hermetic blocking release suite: 426 tests / 2207 assertions / 0
  failures / 0 errors.
- Upgrader regression (CI fresh-install job): Caddyfile change → upgrade
  → new configuration served; unchanged → caddy NOT recreated. Fails
  against rc.1 behavior, passes with the fix.
- Fresh artifact-only install: all services healthy, Doctor 0 FAIL,
  frontend assets 200 with correct MIME.
- Upgrade drills rc.1 → rc.2 (local disposable stack AND the live Azure
  test VM): version 0.3.0-rc.2, Doctor 0 FAIL, no manual caddy
  intervention, HTTPS/login/assets verified, full VM reboot persistence.
- Bounded real CDC smoke on the artifact (PostgreSQL WAL, MySQL binlog,
  MongoDB change streams): reconciliation deltas 0, cutover gates PASS.
- Backup/restore drill: pass.
