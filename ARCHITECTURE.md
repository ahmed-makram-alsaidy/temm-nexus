# Architecture

The platform is a **self-hosted backend & migration control plane**: a
Laravel application (the "Owner Console") that manages its own
infrastructure plus any number of generated backend projects, and can
import/analyze external backends through read-only connectors.

## Top-level layout

```
├── apps/owner-console/        Laravel + Filament control plane (the product)
├── packages/                  Client SDKs: JS, PHP, Dart
├── infrastructure/            Dockerfiles, Caddy, PostgreSQL, Redis configs
├── deploy/                    VPS bootstrap + production Caddy/scripts
├── scripts/                   install / upgrade / create-project / security / release
├── docs/                      operator + product documentation
├── docker-compose.yml         local development stack
├── docker-compose.prod.yml    self-hosted production distribution
└── VERSION                    canonical SemVer (displayed in the console)
```

## The control plane (apps/owner-console)

- **Laravel 13 + Filament 5** admin UI at `/admin`; project surfaces are
  Filament resources/pages; machine endpoints live under `/f/*` (project
  functions) and `/api/*` (health, integrations).
- **First-run gate** (`app/Http/Middleware/EnsurePlatformInitialized`):
  while uninitialized, everything redirects to `/setup`; afterwards `/setup`
  locks. State lives server-side in `platform_settings` (encrypted values).
- **Multi-tenancy model:** each Project row maps to a dedicated PostgreSQL
  database + Redis prefix + storage subtree + API keys + secrets. Isolation
  is enforced per-surface (SQL studio, functions, storage, backups) and
  covered by regression tests.

### Key subsystems

| Subsystem | Entry point | Notes |
|---|---|---|
| Projects & environments | `Services\ControlPlane\OnboardingService`, `EnvironmentService` | wizard-driven, idempotent |
| Project connections | `ProjectConnectionManager` | per-project DB creds from env/vault |
| Migration engine | `Services\ControlPlane\Migration\*` | plan → rehearse → apply with guards |
| Source connectors | `Connectors\ConnectorRegistry` + `Migration\Contracts\SourceAdapter` | read-only by contract |
| AI copilot | `Services\ControlPlane\Ai\*` | BYOK gateway, approval-gated tools, patch workspaces |
| Backups | `BackupCenterService`, `ProjectBackupService` | destinations, policies, restore drills |
| Security | `SecretVaultService`, `SourceWriteGuard`, `AdminAudit` | encrypted secrets, audited actions |

## Runtime topology (production)

```
            Internet
               │  80/443
        ┌──────▼──────┐
        │    caddy    │  TLS (auto with domain), static files, headers
        └──────┬──────┘
     ┌─────────┼─────────┬──────────┐
     ▼         ▼         ▼          ▼
  ┌──────┐ ┌────────┐ ┌────────┐ ┌────────┐
  │ app  │ │horizon │ │scheduler│ │ reverb │   one image, four roles
  └──┬───┘ └───┬────┘ └───┬────┘ └───┬────┘
     ▼         ▼          ▼          ▼
  ┌─────────────────────────────────────┐
  │   application network (internal)    │
  └───────────┬───────────────┬─────────┘
              ▼               ▼
        ┌──────────┐    ┌─────────┐
        │ postgres │    │  redis  │   internal-only networks + volumes
        └──────────┘    └─────────┘
```

- One application image (PHP 8.4-FPM, Alpine) serves all four roles via
  different commands.
- PostgreSQL and Redis sit on an `internal: true` Docker network — no host
  or internet exposure.
- Persistence: named volumes for PostgreSQL data, Redis data, uploads and
  Caddy certificates.

## Data flow: importing an existing backend

1. **Connect** — account/credentials stored encrypted (`ExternalAccountConnection`).
2. **Discover & select** — safe metadata only (management API).
3. **Analyze (read-only)** — schema, RLS, RPC/edge, storage inventory via
   the source adapter; capability probe reports honestly what is/isn't
   accessible.
4. **Scan the client** — optional repository link (path-contained,
   read-only) produces a callsite manifest.
5. **Plan & rehearse** — migration plan against a disposable target; the
   source can never be a target (`SourceWriteGuard`).
6. **Apply — only with explicit operator approval.**

## Versioning & release

- Root `VERSION` (SemVer) is the single source; the console footer and the
  welcome page display it; scripts and docs reference it.
- Distribution packaging + private-reference + secret scans gate every
  release candidate (docs/open-source/RELEASE_PROCESS.md).
