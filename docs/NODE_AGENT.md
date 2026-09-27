# Node Agent Design + Local MVP (Phase 21B)

## Design (remote-ready, not yet deployed)

A lightweight agent runs on each future VPS and reports OUTBOUND to the
Control Plane. There is deliberately no inverse channel:

- Transport: HTTPS `POST /cp-nodes/heartbeat` (JSON), every ~30s.
- Auth: per-node bearer token. Server stores **sha256 only**
  (`infrastructure_nodes.token_hash` + `token_prefix` for lookup).
  Rotation (`php artisan infra:node-token --node=X` or Node Detail →
  "Rotate agent token") instantly revokes the old token; the plaintext
  prints once and is never stored.
- Payload (validated, capped): `cpu/ram/disk` 0–100, `agent_version`,
  `services[]` (`key` allowlisted to the 7 known services, `status`,
  `version`, `endpoint` reference — never credentials).
- Liveness: `computedStatus()` derives health from `last_seen_at`
  (degraded >90s, offline >300s, disabled ⇒ offline). `infra:mark-stale`
  (also scheduled every minute as `cp-node-stale-sweep`) persists
  transitions and files an `infrastructure_events` row.
- Config pull: `GET /cp-nodes/config` returns non-secret operating
  parameters (interval, thresholds). No secrets, no commands.
- **Remote actions are NOT implemented.** The allowed future set is
  fixed in `config/infrastructure.php` (`health_check`,
  `restart_managed_service`, `deploy_project`, `restart_worker`,
  `reload_proxy`) and would ride a SEPARATE signed, allowlisted command
  envelope. Generic shell execution from the browser is out of scope
  permanently (see `docs/CONTROL_PLANE_SECURITY.md`).

## Local MVP (implemented + proven)

Real remote hosts are out of scope, so the MVP simulates three local
nodes through the IDENTICAL ingest path (`NodeHeartbeatService::ingest`):

- `php artisan infra:seed-local` — `node-local-01` (all roles, 7 global
  services), `node-local-02` (queue_worker/realtime),
  `node-local-03` (monitoring/backup), plus per-project single-node
  mapping rows.
- `php artisan infra:heartbeat --node=X [--cpu=.. --ram=.. --disk=..
  --services=k=v,.. --agent-version=..]` — same validation + upsert path
  a remote agent's POST would hit (the HTTP route shares the service).
- `php artisan infra:mark-stale` — sweep; verified: a node silent 2 min ⇒
  `degraded`, 10 min ⇒ `offline`, event recorded, UI reflects it.
- Authenticated browser proof: Nodes list, Node Detail (services, projects
  using the node, health history, token prefix — never the token),
  Services, Health (per-facet), Topology. Screenshots in `docs/phase21/`.

## Resource budget

Target: <50MB RAM per node agent (`config/infrastructure.php`).

- The Phase 21 agent does no work between beats (stateless HTTPS POST from
  cron/systemd timer), so steady-state RSS is effectively the interpreter
  at POST time. Measured on this host for the local simulator path
  (`infra:heartbeat` peak, PHP 8.4 CLI):

| Component | Measured |
|-----------|----------|
| `infra:heartbeat` peak PHP memory (`memory_get_peak_usage`) | **16 MB**¹ |
| Steady-state between beats | 0 MB (no daemon; cron/systemd timer) |
| Remote design envelope (python/sh + curl equivalent) | <10 MB projected |

¹ Measured 2026-09-17 on the stack's PHP 8.4 CLI
(`storage/phase21_memcheck.php`, since removed): `exit=0 peak_mb=16`.
Well under the 50 MB ceiling. A future always-on daemon (if ever needed)
must stay under the same ceiling; the stateless design makes this trivial.
