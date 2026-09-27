# API management (control plane)

Per project: base URL (copyable), `/api/health` (copyable), API version, and the
public route table (method + URI + auth requirement) from a generated snapshot:
`scripts/refresh-project-api-routes.sh <slug>` writes
`projects/<slug>/.control-plane/api-routes.json`, displayed with its freshness
timestamp. Internal routes (admin/horizon/pulse/sanctum) are filtered out.

Analytics: request/exception/slow-endpoint visibility comes from Pulse
(Monitoring page), not a gateway — deliberately no gateway is built here.
