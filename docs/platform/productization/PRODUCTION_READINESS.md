# Production Readiness

> Route: `/admin/projects/{record}/readiness` · Permission: view `projects.view`, acknowledge `readiness.acknowledge`

## Status model

Checklist/status only — **no numeric score**. Statuses: GREEN / YELLOW / RED /
NOT_APPLICABLE. Categories: Database, Auth, Storage, Authorization, Finance,
Realtime, Integrations, Clients, Performance, Backups, Restore, Rollback,
Infrastructure, Security, Secrets, Schema Drift, Observability.

## Machine checks (24H.2)

Auto-populated from platform evidence, persisted in `readiness_checks`:

| Check | Evidence | RED when |
|---|---|---|
| db_reachable | project DB connection | unreachable |
| db_health_status | project health_status | unhealthy |
| backup_freshness | last completed backup run | none / > 96h |
| restore_test_age | last passed restore drill | never tested / > 60d |
| schema_drift_unresolved | Schema Diff findings | dangerous drift recorded |
| secret_presence | vault rows for environment | (yellow when none) |
| reverb_health | Reverb config | (yellow when unset) |
| https_api_domain | project api_domain | (yellow when unset) |
| monitoring_present | Pulse data | (yellow when absent) |

## Manual checks & acknowledgements (24H.3)

Operators can add manual checks (things the platform cannot prove) and
acknowledge with **who / when / note** — audited (`READINESS_ACKNOWLEDGED`).
A manual acknowledgement can **never silently override a RED machine check**:
the service refuses with 422; only YELLOW manual checks can be acknowledged.

## Blockers (24H.4)

`ReadinessService::blockers` = RED machine checks. The page renders an
explicit BLOCKERS section (no restore test, dangerous drift, DB unhealthy, …).
Dangerous schema drift flows in from Schema Diff runs via `recordDrift`.

## History (24H.5)

`ReadinessService::snapshot` stores a `readiness_snapshots` row (counts +
blockers) before every release/cutover; the page shows the last 10 snapshots.
