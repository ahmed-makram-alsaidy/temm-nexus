# TEMM Nexus v0.6.3 — Wasla multi-project VPS hosting runbook

> **Scope:** self-hosted backend infrastructure for Wasla and other company projects. **Developer coding agents run only on the operator's Windows PC**, not on this VPS. This is a reviewed plan and optional Compose change, **not evidence that anything has been deployed**.
>
> **Base:** `release/0.6.3`, `VERSION = 0.6.3`. The repository default `main` contains an older version and its README is stale. Pin the accepted release revision/artifact before provisioning a server.

## 1. Intended deployment

- Ubuntu 24.04 LTS, Docker Engine and Compose plugin.
- Nexus: Laravel/Filament owner console, Caddy HTTPS, PostgreSQL 17, Redis 8, Horizon queues, Scheduler, Reverb WebSockets, persistent volumes and backup center.
- One Nexus project per application/business boundary. Do not merge tenant databases or recycle one application's production credentials for another.
- Every project has separate **Development**, **Staging**, and **Production** environments; pick the active environment explicitly.
- Wasla frontend/mobile clients keep their current production endpoints until an approved cutover. A successful migration *dry run* is not a production cutover.
- The existing WhatsApp Baileys gateway is a **separate Docker service** to migrate independently, not a feature to rebuild inside Nexus. Never run two active gateways against the same WhatsApp account/session.
- **No server coding agent**: `opencode` is available only when `--profile managed-developer-agent` is explicitly used. No provider secrets or Azure Foundry development keys belong on this VPS.

## 2. Sizing (starting estimates, not throughput guarantees)

Nexus's own `docs/open-source/VPS_REQUIREMENTS.md` gives:
- Evaluation: 2 vCPU, 4 GiB RAM, 20 GiB disk.
- Small production: **4 vCPU, 8 GiB RAM, 60 GiB disk**, a handful of projects.
- Multi-project: **8 vCPU, 16 GiB RAM, 120 GiB+ disk**, multiple projects, migrations/backups.

Because our VPS may also run the separate WhatsApp gateway, choose from **8 vCPU / 16 GiB** when running multiple business systems on the same machine, unless measured lower load justifies a smaller starting size. OpenCode is explicitly excluded, but database/import jobs, queue workers, WhatsApp, logging and backups still need headroom. For hundreds of tenants or millions of monthly shipments, load tests determine whether PostgreSQL and workers need their own nodes. Keep free disk for backups: at least twice the largest database plus operational headroom. Avoid committing to any cloud spend until the Azure subscription, credit expiry, eligible services and VM region are verified.

## 3. Staging acceptance gates (read-only or disposable first)

1. Verify the Git revision/artifact is exactly release **0.6.3**; inspect the published changelog and package. Do not use default `main`.
2. Provision **a new clean staging VPS** in the approved cloud subscription; allocate budget alerts; enable least-privilege access, restrictive firewall (22 from the operator IP/VPN only, 80/443 as needed), DNS and TLS.
3. Confirm container selection **before starting anything**:
   ```sh
   # Inspect only; no running services and no customer data touched.
   docker compose -f docker-compose.prod.yml config --services
   ```
   The default service list must NOT include `opencode`; `postgres`, `redis`, `migrate`, `assets`, `app`, `horizon`, `scheduler`, `reverb`, and `caddy` remain.
   The explicit opt-in form, for comparison **only**, is `docker compose -f docker-compose.prod.yml --profile managed-developer-agent config --services`.
4. Prepare private config from the template with generated secrets. Keep `.env`, backups, WhatsApp sessions and provider keys outside GitHub; preserve `APP_KEY` across updates.
5. Run `./scripts/install.sh --check` (readiness only), review output, then get deployment approval before executing the actual installer.
6. Confirm first-run setup, protected console login, HTTPS, private DB/Redis, Redis/Horizon/Scheduler/Reverb health, project creation and tenant isolation.
7. Create a **disposable** project and its development database with v0.6.3 `Connections -> Provision database`; check the active environment binding, Tables and Health. Do not enable a real production transfer.
8. Test backup + restore on a **separate disposable database** and storage volume. Merely creating a dump is not a proven restore.
9. Validate that ordinary Nexus functions operate without the managed OpenCode runtime. The Developer Agent screen may legitimately report the absent runtime; do not treat it as backend downtime.
10. Measure CPU, memory, disk, queue latency, database performance and websocket stability under staged, synthetic load. Preserve a rollback route before onboarding any client.

## 4. Wasla onboarding: no direct production switch

- Attach Wasla Supabase with read-only source permissions and run Connect -> Analyze -> Plan -> disposable Rehearsal.
- Inventory Auth, RLS, schema, RPC, Edge Functions, realtime, storage, attachments, integrations, background jobs and Flutter endpoints. Map each capability to the destination or explicitly mark it unsupported. Preserve company/role boundaries and COD transaction integrity.
- **Nexus 0.6.1 notes that its Supabase connector does not declare Live Sync support.** Plan a maintenance/cutover strategy accordingly; do not assume zero downtime.
- Provision a separate Nexus project/DB/environment, test migrations on disposable targets, compare source/destination counts and financial totals, and run end-to-end user scenarios.
- Only after documented approval: take final source snapshot, manage writes during cutover, switch endpoint/DNS deliberately, monitor, and keep the original data accessible for rollback. No silent production mutation.
- Integrate the existing WhatsApp gateway separately after backing up encrypted `auth_sessions`. `sessionStore.js` currently uses Supabase; moving the container alone does NOT migrate that data integration. Never start both gateway instances simultaneously.

## 5. Updates and disaster recovery

- Record original commit, environment versions, disk metrics and a **restorable** pre-change backup before each upgrade.
- `scripts/upgrade.sh` uses forward schema migrations; do not invoke `migrate:fresh` or assume old schema can always be recovered just by checking out an older app image.
- Never run `docker compose down --volumes`, remove DB/storage volumes, `git reset --hard`, destructive cloud changes or formatting commands.
- Verify database restore, uploaded file restore, encrypted secrets/`APP_KEY`, and saved Docker volumes on a sacrificial target.
- Keep separate measured recovery objectives for Nexus console, project databases, and the WhatsApp gateway.
- Do not delete the old WhatsApp VM or Supabase data until a controlled acceptance and retention period has passed.

## 6. Review checklist

- [ ] Exact 0.6.3 artifact/commit pinned
- [ ] Compose config validates; default stack excludes `opencode`; all required Nexus services remain
- [ ] Staging backup and restore drill passed
- [ ] Security and tenant isolation tested; no public DB/Redis/OpenCode ports
- [ ] Azure credit and expiry verified before VM purchase/provision
- [ ] Load test and cost ceiling approved
- [ ] Wasla source remains unchanged during analysis/rehearsal
- [ ] WhatsApp migration separately approved and failback tested
- [ ] Production cutover explicitly authorized; post-cutover monitoring/rollback verified

**Reference:** `docs/open-source/RELEASE_NOTES_0.6.3.md`, `docs/managed-database-lifecycle.md`, `docs/open-source/VPS_REQUIREMENTS.md`, `docs/agent-runtime/OPENCODE_INTEGRATION.md`, `docker-compose.prod.yml`.
