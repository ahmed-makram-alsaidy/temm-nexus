# Backup Center

> Route: `/admin/projects/{record}/backups` (extended) · Permission: trigger `backups.trigger`, policy/destinations `backups.policy`, drills `restore.local`

## What was added on top of the Phase 9 backups

The existing Backups page (trigger + verify + history) became an operational
Backup Center with four new sections: **Backup health**, **Restore drills**,
**Offsite destinations** and policy management actions.

## Policies (24D.1)

`backup_policies` — per project/environment: scope (database|storage),
schedule (cron 5-field; presets hourly/daily/weekly normalized to cron),
retention days, encryption status, destination reference, status
(active|paused), last run / last restore-test timestamps. `Run policy now`
executes immediately through the existing `ProjectBackupService` (pg_dump as
the project role, checksum + verify).

## Destinations (24D.2)

Generic destination architecture: `local` and `s3-compatible` drivers.
S3-compatible covers any endpoint (Backblaze B2, Cloudflare R2, MinIO, AWS S3,
…) — never hardcoded to AWS. Access keys are stored only as vault secret
references (`secret_ref`); endpoint URLs are validated. The health strip
reports honestly whether offsite is configured.

## Runs (24D.3)

`backup_runs` — run id, policy, environment, type (database|storage|
restore_drill), trigger (manual|schedule|drill), start/finish, size,
checksum, destination, status, error, metadata. Failures are recorded
honestly (a failed run is never reported as complete).

## Restore drill (24D.4)

`BackupCenterService::requestRestoreDrill` restores a COMPLETED backup into
a NEW disposable `restore_drill_<timestamp>_<rand>` database, validates the
restored table count, then always drops the disposable database. Guards:

- the source file must exist under `/backups` and pass a realpath traversal check;
- only completed backups can be drilled;
- the drill shell is disabled unless `RESTORE_DRILL_ENABLED=true` (default in local dev);
- the active database is NEVER touched — there is still no restore-to-live button;
- every drill is audited (`RESTORE_DRILL_REQUESTED/PASSED/FAILED`).

## Health (24D.5)

Per policy: `HEALTHY` (on schedule), `WARNING` (latest backup too old / never
run, or restore test older than 30 days), `FAILED` (latest run failed),
`NEVER_TESTED` (no restore drill). Overall status + last successful backup +
last successful restore test are shown at the top of the page.

## Alerts (24D.6)

Health integrates with the audit/monitoring surfaces; no external provider
notifications are sent unless explicitly configured later.
