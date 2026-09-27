# Backups UI (control plane)

Project Backups page over the Phase 9 engine: prominent Last backup / Last
verified / Last restore-drill states, live DB size, history table with per-row
Verify, and audited Trigger (pg_dump as the project's own role into
`backups/data`, checksum sidecar, `backup_records` row).

Restore deliberately has NO button — it stays a runbook operation
(`scripts/backups/restore-one-db.sh` to a NEW database).

Proof (18L): trigger → listed with valid checksum → verify sets verified_at →
page shows the record (`BackupsAuditTest`).
