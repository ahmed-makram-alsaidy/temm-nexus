# Self-Hosting Guide

The platform is designed to run on infrastructure you control, with a
manageable operating surface.

## Topology

- One Docker Compose stack (docker-compose.prod.yml): app, PostgreSQL,
  Redis, Horizon, scheduler, Reverb, Caddy.
- Only ports 80/443 are published; databases are internal-only.
- Persistence via named volumes (database, redis, uploads, certificates).

## Operating model

| Task | How |
|---|---|
| Install | ./scripts/install.sh (or manual — see ../../INSTALL.md) |
| First run | http://host/setup (wizard; see FIRST_RUN.md) |
| Upgrade | ./scripts/upgrade.sh (see ../../UPGRADE.md) |
| Backup | Backup Center UI + scripts (see ../../BACKUP.md) |
| Logs | docker compose -f docker-compose.prod.yml logs <service> |
| Metrics | built-in dashboards (Pulse/Horizon) in the admin console |

## Where state lives

- `backend-plane-pgdata` volume — ALL databases (platform + projects)
- `backend-plane-app-storage` volume — uploads + private app storage
- `.env` — configuration + generated secrets (file permission 600)
- `backups/data/` (bind mount) — staged dumps

Losing the pgdata volume loses everything: back it up (BACKUP.md).

## Multi-project operation

Projects created in the console get dedicated databases, Redis prefixes,
storage subtrees, API keys and secrets. New project provisioning on the VPS
(database role + Caddy route) uses scripts/create-project.sh. Isolation is
regression-tested (see docs/platform/productization/).

## Development vs production

- docker-compose.yml — development: source bind-mounts, optional devtools
  profile (pgAdmin, Mailpit — loopback-only, disabled by default)
- docker-compose.prod.yml — self-hosted production: baked image, no devtools

## Resource tuning

Configurable memory limits live in docker-compose.prod.yml; the defaults
suit a small single-server install. See VPS_REQUIREMENTS.md.
