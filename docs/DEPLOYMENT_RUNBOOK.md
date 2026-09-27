# Deployment runbook (Phase 12) — future VPS, rehearsed locally where safe

## First deploy (fresh Ubuntu 24.04)

1. `bootstrap.sh <repo-url>` → hardening, Docker, UFW 22/80/443, deploy user, clone.
2. As deploy user: `cp .env.example .env`, fill secrets (32+ chars), `chmod 600 .env`.
3. `docker compose up -d postgres redis` → wait healthy.
4. `docker compose up -d` (all) → `caddy validate` passes at boot.
5. `sh infrastructure/monitoring/healthcheck.sh` → OK.
6. Per project: `scripts/create-project.sh <slug>` (DB/role/env/Caddy stanza).
7. Deploy app: `sh deploy/scripts/deploy-app.sh <slug>` (migrate → caches →
   workers → verify).
8. DNS → VPS, Caddy issues certs automatically; re-run health-verify per host.
9. First backup: `backup-all.sh` + `verify-backup.sh` + `offsite-sync.sh`.

## Updates / rollback / workers

- Update: `deploy-app.sh <slug> [ref]` (same migrate-first pipeline).
- Rollback: `rollback-app.sh <slug>` (previous image; review down-migrations).
- Workers: `docker compose restart <slug>-horizon`; Reverb: `restart <slug>-reverb`.
- Verify anytime: `sh deploy/scripts/health-verify.sh <slug> [host-header]`.

## GATE 12 — local dry-run (2026-09-16)

Production deploy NOT run (no VPS, per safety rules). Locally simulated:

- `docker compose config` → valid (Phase 2).
- Full `up` + health-gated dependencies + `health-verify` equivalent:
  `curl -H 'Host: console.test' localhost/api/health` → `"ok":true` (Phase 8).
- `deploy-app.sh` migrate/cache/worker/verify sequence mirrors the exact commands
  already executed live against the template proof app (migrate ✅, config/route
  cache compatible, `queue:work` ✅).
- `bootstrap.sh` syntax-checked (`bash -n` equivalent review; NOT executed —
  it targets a fresh Ubuntu root and would violate safety rules locally).
