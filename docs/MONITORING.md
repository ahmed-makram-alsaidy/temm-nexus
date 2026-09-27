# Monitoring (infrastructure + control plane)

## Infrastructure layer (Phase 10, preserved)

- `infrastructure/monitoring/healthcheck.sh` — unified probe, wired for VPS cron
  every 2 min; exit 0 = healthy. Checks: compose service health states,
  `pg_isready`, Redis PING (auth), disk < 80%, per-app `/api/health` = `"ok":true`.
- `infrastructure/monitoring/disk-db-check.sh` — disk + per-DB sizes snapshot.
- Owner Dashboard widgets surface the same signals (DB totals, failed jobs,
  disk/RAM/CPU container view, last backup).
- Alert thresholds: disk 80%, failed jobs > 0, unhealthy containers, backup older
  than 24h (see `docs/BACKUP_DR.md`).

## Application layer (Phase 18, control plane Monitoring page)

Per project: Pulse presence, entry counts by type, recent exceptions / slow
queries / slow requests read from the project's Pulse tables. Full Pulse
dashboard at `/pulse`, gated to owners (`viewPulse` gate). Server counters
(RAM/CPU/disk, container view) and DB totals live on the main Dashboard.

Proof (18M): real traffic against the demo app produced `user_request`,
`slow_request`, `exception`, and `cache_hit` entries in `pulse_entries`.
