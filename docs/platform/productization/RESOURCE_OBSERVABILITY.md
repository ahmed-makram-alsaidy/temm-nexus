# Resource Observability

> Route: `/admin/projects/{record}/resources` · Permission: view `infrastructure.view`, thresholds `infrastructure.manage`, cost `settings.manage`

## Honesty rules

- Only metrics the infrastructure actually exposes are recorded — everything
  unavailable is shown as `unavailable`, never fabricated.
- Node-level CPU/RAM are labelled `attribution: node` because per-project
  splits are not measurable on a shared node.
- Cost figures are operator-entered and always labelled **ESTIMATE**.

## Metrics (24G.1–24G.2)

| Metric | Source | Attribution |
|---|---|---|
| db_size_bytes, db_connections | project PostgreSQL (pg_database_size / pg_stat_activity) | project |
| redis_memory_bytes | project Redis namespace INFO | project |
| queue_depth, failed_jobs | project Redis queues | project |
| storage_bytes | project checkout storage dir (du) | project |
| backup_bytes | recorded backup runs sum | project |
| node_cpu_percent, node_ram_used_bytes | /proc inside the node | node |

## Trends (24G.3)

24h / 7d / 30d windows from `resource_metric_samples` (bounded at 2000
points); retention is only what samples exist.

## Capacity warnings (24G.4)

`resource_thresholds` (warning + critical per metric) are configurable per
project; `warnings()` lists metrics over threshold; the page shows a
warnings strip.

## Cost estimation (24G.5)

`cost_entries` — operator-entered monthly cost (e.g. shared VPS 600 EGP),
allocation strategy `equal` / `manual` / `resource_weighted` (DB-size share
over the last 7 days when metrics are reliable). Output carries
`label: "ESTIMATE — not a billing figure"` and the allocation basis. No
billing engine.

## Tests

`tests/Feature/Phase24/ResourcesObservabilityTest.php` — honest absence,
project-isolated sums, threshold warning/critical, estimate labeling,
unknown-metric rejection, trend windows.
