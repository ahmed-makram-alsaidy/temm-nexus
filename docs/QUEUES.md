# Queues (control plane)

Per project: Horizon state (masters/supervisors seen in the project's Horizon
Redis namespace), per-queue pending depths, processed/failed counters, and the
`failed_jobs` table with audited **retry** (`queue:retry <uuid>`) and **delete**
(`queue:forget <uuid>`) executed in the project checkout via the artisan bridge.

## Runtime proof (18I, PASS)

Horizon ran in the demo project and processed jobs with no `queue:work` anywhere:
`ProcessDemoOrder RUNNING → DONE`, order flipped to processed. A deliberate
`FailingDemoJob` landed in `failed_jobs`, retry re-queued it (failed again with a
new row — tries=1 by design), forget removed it (count 0). Fix found en route:
retry/forget address jobs by UUID, not numeric id.

Production note: supervisor queue list is env-driven (`REDIS_QUEUE`), so each
project's Horizon watches its own namespaced queue.
