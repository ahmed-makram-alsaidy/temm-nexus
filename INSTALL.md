# Installation

Complete installation guide for the self-hosted platform. For the short
version see the README Quick Start.

## Prerequisites

- **OS:** Linux (Ubuntu 24.04 LTS recommended; any distribution with Docker works)
- **Docker Engine 24+** and the **Compose plugin** — [install guide](https://docs.docker.com/engine/install/)
- **A non-root sudo user** for running the stack
- **Ports:** 80 and 443 free on the host (the only published ports)
- **Resources:** see [docs/open-source/VPS_REQUIREMENTS.md](VPS_REQUIREMENTS.md)
- Optional: a domain with DNS A/AAAA record pointing at the server (for HTTPS)

## Option A — installer script (recommended)

```bash
git clone https://github.com/ahmed-makram-alsaidy/temm-nexus.git temm-nexus
cd temm-nexus
./scripts/install.sh
```

What it does:

1. Verifies Docker, Compose, daemon access and free disk (5 GB minimum).
   If anything is missing it prints instructions and **stops — nothing is modified**.
2. Creates `.env` from `.env.example` **if missing**, generating strong
   random secrets (APP_KEY, database and Redis passwords, Reverb keys).
   An existing `.env` is never overwritten.
3. Creates runtime directories (never empties existing ones).
4. Builds the application image and starts the stack (first build takes
   several minutes).
5. Waits for health checks and prints the next step.

Re-runs are safe: an existing installation is upgraded in place; data
volumes and `.env` are preserved.

Dry run (checks only, changes nothing):

```bash
./scripts/install.sh --check
```

## Option B — manual

```bash
git clone https://github.com/ahmed-makram-alsaidy/temm-nexus.git temm-nexus
cd temm-nexus
cp .env.example .env
# edit .env: set every CHANGE_ME secret, APP_URL, optionally PRIMARY_DOMAIN
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps   # all services should turn healthy
```

Generate `APP_KEY` if you skipped it:

```bash
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
```

## First run

Open `http://<your-host>/setup`. While the platform is uninitialized,
every other page redirects there. The wizard:

1. Verifies PHP/extensions, APP_KEY, PostgreSQL, Redis, storage, queue
2. Collects platform identity (generic defaults are safe)
3. Creates the first platform owner — a strong password is enforced and
   there is no default
4. Records the platform URL; explains HTTPS honestly (see DOMAIN_TLS.md)
5. Offers optional mail and AI configuration
6. Locks itself after completion

Details: [docs/open-source/FIRST_RUN.md](FIRST_RUN.md).

## What runs where

| Service | Image | Role |
|---|---|---|
| `assets` | built application image | one-shot: publishes Filament CSS/JS/fonts into the edge docroot |
| `migrate` | built application image | one-shot: database schema migration |
| `app` | built application image | PHP-FPM web application |
| `horizon` | built application image | queue worker |
| `scheduler` | built application image | cron-like scheduler |
| `reverb` | built application image | websocket server |
| `postgres` | postgres:17-alpine | database (internal-only) |
| `redis` | redis:8-alpine | cache/queues (internal-only) |
| `caddy` | caddy:2-alpine | TLS termination / reverse proxy |

Only Caddy publishes ports (80/443). PostgreSQL and Redis live on an
internal Docker network and are unreachable from the host or internet.

## After installation

- HTTPS: [docs/open-source/DOMAIN_TLS.md](DOMAIN_TLS.md)
- Mail (optional): set `MAIL_MAILER=smtp` + credentials in `.env`, then
  `docker compose -f docker-compose.prod.yml up -d`
- AI (optional, BYOK): configure in the admin UI —
  [docs/open-source/AI_BYOK.md](AI_BYOK.md)
- Backups: [BACKUP.md](../BACKUP.md)

## Uninstall

Stop and remove containers (data volumes remain until explicitly deleted):

```bash
docker compose -f docker-compose.prod.yml down            # keep data
docker compose -f docker-compose.prod.yml down --volumes  # DELETE all data
```

`--volumes` deletes PostgreSQL data, Redis data, uploads and TLS
certificates. There is no undo.
