# Backup & disaster recovery (Phase 9) — CRITICAL

## PostgreSQL

- Per-project: `backup-one-db.sh <db>` → `/backups/<db>_<UTC>.dump` (custom format,
  compressed) + `.sha256`. Full-server option: `backup-all.sh`.
- Restore: `restore-one-db.sh <file> <new_db> <owner>` — always to a NEW database,
  refuses if target exists, re-applies CONNECT isolation. No in-place overwrite.
- Verify: `verify-backup.sh <file>` (checksum + archive listing).
- Retention (`retention-cleanup.sh`, `DRY_RUN=1` supported): **daily 7 / weekly 4
  (Sundays) / monthly 6 (1st)** per database. Cron on VPS: nightly backup-all,
  weekly verify, monthly retention.

## Files

- `backup-files.sh <slug>` tars `storage/app` per project + checksum.
- Production files live on VPS SSD; large uploads move to S3-compatible disk
  without code changes (Phase 16). Files + DB backups sync offsite together.

## Offsite (mandatory for production)

- `offsite-sync.sh` → `rclone sync /backups offsite:laravel-infra-backups`
  (R2/B2/S3; `rclone.conf.example` template, real config 0600 on VPS only).
- Local-only backups are explicitly NOT sufficient.

## Restore drill — TESTED ✅ (2026-09-16, live local run, TEST data only)

1. Sample data: `gate_a_db.proof_a` seeded → **51 rows**,
   baseline `md5(string_agg(note ORDER BY id))` = `3a0bd69498ea9a83284a783c2790cdb0`
2. Backup: `gate_a_db_20260916T050407Z.dump` (3.8 KB) + sha256; verify → 25 entries OK
3. Dropped TEST `gate_a_db` (+ its role) — no other database touched
4. Recreated owner role; restored to `gate_a_restored` (refusal-guards exercised)
5. Post-restore: **51 rows, checksum `3a0bd694…cdb0` — exact match** ✅
6. Renamed back to `gate_a_db`; owner login + count re-verified ✅

Backup files from the drill remain in `backups/data/` (git-ignored).
