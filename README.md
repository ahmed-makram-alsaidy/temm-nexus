# TEMM Nexus

**Move your backend. Own your infrastructure.**

TEMM Nexus is an open-source, self-hosted backend migration and control
plane for analyzing, migrating, validating, and operating backend
projects on infrastructure you control.

> **Status:** pre-1.0 release candidate (`0.2.0-rc.2`). Expect breaking
> changes before 1.0. External VPS verification is still pending — see
> [Release status](#release-status). See [CHANGELOG.md](CHANGELOG.md).

---

## What is TEMM Nexus?

TEMM Nexus helps teams move backend projects between platforms and
infrastructure — without blindly rewriting everything by hand — and then
run them on a backend platform you operate yourself:

- **Create new backend projects** — a console that provisions project
  databases, environments, APIs, auth, storage, realtime, queues and
  scheduled tasks on infrastructure you control.
- **Import existing backends** — connect a Supabase project or a MongoDB
  database (both first-party connectors), analyze it
  read-only (schema, RLS policies, RPC/edge functions, storage; for MongoDB:
  inferred schema, relationship candidates, mapping strategies), plan a
  migration, rehearse it against a disposable target, and only then decide.
  Nothing is ever written to the source. New sources can be added through the
  Connector SDK (see [docs/connectors/CONNECTOR_SDK.md](docs/connectors/CONNECTOR_SDK.md)).
- **AI Migration Copilot (optional, BYOK)** — bring your own key
  (OpenAI, Gemini, Anthropic, OpenRouter, OpenAI-compatible) for migration
  advice, patch generation with human approval, and test/repair loops.
  Without a key, everything except AI features still works.
- **Operations** — backup center, health checks, database browser and SQL
  studio, logs, metrics, audit log, team RBAC, client SDKs (JavaScript,
  PHP, Dart).

## Why TEMM Nexus?

- **Portability** — backend decisions should not be one-way doors. TEMM
  Nexus analyzes what you actually have (schema, policies, functions,
  storage, client callsites) and maps it honestly, including what it
  *cannot* map automatically.
- **Ownership** — after migration, the platform runs on your server:
  your PostgreSQL, your Redis, your storage, your keys.
- **Validation before commitment** — migrations are planned, rehearsed
  against disposable targets, and compared for determinism before you
  cut anything over. Sources are strictly read-only.
- **Connector architecture** — import sources are plugins with a
  declared capability matrix; honest partial support is a feature, not
  a bug.

## What it is NOT

- **Not a managed SaaS.** You run it on your own server; you are the operator.
- **Not an auto-migrator.** Migration plans require your approval at every
  gate; sources are strictly read-only.
- **Not cloud-connected.** There is no telemetry, no analytics, no crash
  upload — the platform makes no outbound calls except ones you configure
  (mail, AI provider with your key).

## Supported Sources

| Source | Status |
|---|---|
| Supabase | Supported (first-party connector) |
| MongoDB | Supported (first-party connector) |
| Example JSON | Reference connector (Connector SDK demo) |
| Firebase / PostgreSQL / MySQL | Roadmap — not yet available |

See the [public roadmap](#release-status) and
[docs/connectors/](docs/connectors/) for connector architecture.

## Core capabilities

- **Migration Center** — guided, read-only import with capability probes,
  compatibility analysis, schema/data migration planning, rehearsal
  against disposable targets, and validation with determinism checks
- **AI-assisted migration planning** (BYOK) with human-approval gates
- **Client repository scanner** — scans your application code for
  backend callsites (Auth, Storage, Realtime, API, Functions) to size
  the migration
- **Backend platform** — project databases and environments, secrets
  vault, storage, realtime (websockets), queues, scheduled tasks,
  backups and restore, health checks, logs and metrics, audit log, team
  RBAC, client SDKs (JavaScript, PHP, Dart)
- **Self-hosted installation** — Docker-based installer, first-run
  setup wizard, upgrade and rollback tooling

## Quick Start (Docker, Linux VPS)

```bash
git clone https://github.com/ahmed-makram-alsaidy/temm-nexus.git temm-nexus
cd temm-nexus
./scripts/install.sh          # checks Docker, creates .env with generated secrets, starts the stack
```

Then open **http://your-server/setup** in a browser. The first-run wizard
verifies the stack, creates the first platform owner (there is **no default
password**), and locks itself.

Prefer manual steps? See [INSTALL.md](INSTALL.md).

## Migration workflow

1. **Connect** a source through a connector (read-only credentials;
   Supabase management metadata + read-only database role, or a MongoDB
   connection string).
2. **Analyze** — the capability probe and compatibility analysis report
   what was found *and* what could not be analyzed.
3. **Plan** — review the generated migration plan; adjust mapping
   strategies where needed.
4. **Rehearse** — run the migration against a disposable target and
   compare results for determinism.
5. **Decide** — only then migrate into a project you control.

Details: [docs/open-source/SUPABASE_IMPORT.md](docs/open-source/SUPABASE_IMPORT.md),
[docs/connectors/MONGODB_CONNECTOR_DESIGN.md](docs/connectors/MONGODB_CONNECTOR_DESIGN.md).

## Connector SDK

Connectors declare a manifest, capabilities, credentials model, and a
read-only source adapter. The [Example JSON connector](docs/connectors/EXAMPLE_JSON_CONNECTOR.md)
is a complete, runnable reference. Build your own:

- [docs/connectors/CONNECTOR_SDK.md](docs/connectors/CONNECTOR_SDK.md)
- [docs/connectors/BUILD_YOUR_FIRST_CONNECTOR.md](docs/connectors/BUILD_YOUR_FIRST_CONNECTOR.md)
- [docs/connectors/CONNECTOR_TESTING.md](docs/connectors/CONNECTOR_TESTING.md)

Third-party connectors are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## AI Copilot (BYOK)

Optional. Configure a provider in **Admin → AI Providers** with your own
key (stored encrypted at rest). Nothing is sent to any AI provider unless
you configure a key and invoke AI features yourself. See
[docs/open-source/AI_BYOK.md](docs/open-source/AI_BYOK.md).

## Security model

No default credentials; first owner created through a locked first-run
wizard; secrets encrypted at rest; PostgreSQL/Redis never host-published
in production; rate-limited surfaces; security headers at the edge; no
telemetry. Reporting: [SECURITY.md](SECURITY.md). Distribution security
posture: [docs/open-source/DISTRIBUTION_SECURITY.md](docs/open-source/DISTRIBUTION_SECURITY.md).

## Architecture

TEMM Nexus is a Laravel (PHP 8.3+) application served by PHP-FPM behind
Caddy, with PostgreSQL 17 and Redis 8, distributed as Docker images built
from this repository. See [ARCHITECTURE.md](ARCHITECTURE.md).

## Requirements

- Linux server (Ubuntu 24.04 recommended) with Docker Engine + Compose plugin
- 2 vCPU / 4 GB RAM minimum for evaluation; 4 vCPU / 8 GB for small
  production (see [docs/open-source/VPS_REQUIREMENTS.md](docs/open-source/VPS_REQUIREMENTS.md))
- A domain (for HTTPS) or an IP address (HTTP bootstrap only)

## Documentation

- Self-hosting guide: [docs/open-source/SELF_HOSTING.md](docs/open-source/SELF_HOSTING.md)
- First run: [docs/open-source/FIRST_RUN.md](docs/open-source/FIRST_RUN.md)
- Domain & TLS: [docs/open-source/DOMAIN_TLS.md](docs/open-source/DOMAIN_TLS.md)
- Installation: [INSTALL.md](INSTALL.md) · Upgrades: [UPGRADE.md](UPGRADE.md)
  · Rollback: [docs/open-source/UPGRADE_ROLLBACK.md](docs/open-source/UPGRADE_ROLLBACK.md)
- Backup & restore: [BACKUP.md](BACKUP.md) and
  [docs/open-source/BACKUP_RESTORE.md](docs/open-source/BACKUP_RESTORE.md)
- Troubleshooting: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- Client SDKs: [docs/client-sdk/](docs/client-sdk/)

## Release status

TEMM Nexus is a **release candidate**, not a stable release:

- Pre-1.0; breaking changes are possible between releases.
- **External VPS verification is still pending** — installation is
  verified through local disposable stacks and CI; a published,
  long-running verification on an external public VPS has not been
  completed yet (see
  [docs/open-source/EXTERNAL_VPS_VERIFICATION.md](docs/open-source/EXTERNAL_VPS_VERIFICATION.md)).
- MongoDB content migration has documented limitations (e.g. GridFS
  content migration and change-stream/incremental sync are deferred —
  see [docs/connectors/MONGODB_CONNECTOR_DESIGN.md](docs/connectors/MONGODB_CONNECTOR_DESIGN.md)).
- Some semantic mappings are partial where documented; the analysis
  reports them honestly instead of guessing.

**Public roadmap** (no dates promised): additional community connectors —
Firebase, PostgreSQL and MySQL import sources — plus the pending external
VPS verification.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md). Connector proposals welcome —
see [docs/platform/productization/CONNECTOR_ARCHITECTURE.md](docs/platform/productization/CONNECTOR_ARCHITECTURE.md).

## Security reporting

**Do not open a public issue for security reports.** Use GitHub's
private vulnerability reporting for this repository — see
[SECURITY.md](SECURITY.md).

## License & trademark

Licensed under the [GNU AGPL-3.0-only](LICENSE) — Copyright © 2026
Ahmed Makram El-Saidy (see [COPYRIGHT.md](COPYRIGHT.md)). Third-party
dependencies keep their own licenses. "TEMM Nexus" is the name of the
official project — usage policy in [TRADEMARKS.md](TRADEMARKS.md).
