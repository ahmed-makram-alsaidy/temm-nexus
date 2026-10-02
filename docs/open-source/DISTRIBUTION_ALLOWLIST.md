# Distribution Allowlist (Phase 26A.2)

Explicit rules for what may be included in a public distribution artifact of
this platform. The packaging step (`scripts/release/package-dist.sh`) enforces
these rules; `scripts/release/private-ref-scan.sh` verifies the result.

## Included (distribution surface)

| Path | Why |
|---|---|
| `apps/owner-console/` | Platform application (minus `vendor/`, `node_modules/`, `storage/` runtime output, `tests/` private fixtures) |
| `packages/backend-sdk-js/`, `packages/backend-sdk-php/`, `packages/backend_sdk_dart/` | Client SDKs (minus `dist/`, `.dart_tool/`, `build/`) |
| `infrastructure/` | Dockerfile, Caddy, PostgreSQL, Redis, monitoring configs |
| `deploy/` | Production Caddyfile, bootstrap + health scripts |
| `scripts/` | install / upgrade / create-project / security / release tooling |
| `docs/*.md` (operator docs) | Configuration, operations, security, runbooks |
| `docs/product/` | Product documentation: design system, IA, UX audit, baseline, and the 0.4.0 phase gate / release / acceptance reports (public on main) |
| `docs/ai/` | Nexus AI architecture, Inspect Mode, action safety |
| `docs/platform/` | Product documentation (generalized) |
| `docs/client-sdk/` | SDK integration docs |
| `docs/open-source/` | Open-source governance, self-hosting, release docs |
| `examples/`, `sdk-integration-demo/` (sources only) | Minimal integration examples (no build output) |
| `docker-compose.yml`, `docker-compose.prod.yml`, `.env.example`, `.gitignore` | Distribution infrastructure |
| `README.md`, `INSTALL.md`, `UPGRADE.md`, `BACKUP.md`, `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `ARCHITECTURE.md`, `TROUBLESHOOTING.md`, `CHANGELOG.md`, `VERSION` | Root documentation |
| `.github/` | CI workflows + issue templates (files prepared; not pushed by this phase) |

## Excluded (never distributed)

| Path / pattern | Class |
|---|---|
| `.env`, `.env.*` (except `.env.example`) | SECRET |
| `PHASE*.md`, `FINAL_INFRASTRUCTURE_REPORT.md` (REPO ROOT only — the exclude is anchored `./`) | private root-level history; `docs/product/PHASE*.md` gate reports are PUBLIC and ship |
| private operator evidence directories under `docs/` | private customer artifacts |
| `docs/phase18-evidence/`, `docs/phase20*/`, `docs/phase21/`, `docs/phase22/`, `docs/control-plane-screenshots/` | private evidence / build output |
| `docs/platform/**/PHASE*_*.md` | phase matrices naming private dogfood |
| private dogfood fixture classes under `apps/owner-console/tests/Feature/` (e.g. `Phase23/`) | private regression fixtures (self-skipping locally; not needed by public CI) |
| `projects/` | private project working trees |
| `backups/` | runtime dumps |
| `**/vendor/`, `**/node_modules/`, `**/.next/`, `**/dist/`, `**/build/`, `**/.dart_tool/` | build output |
| `**/storage/` runtime files (framework caches, logs, `app/private/**` incl. AI workspaces) | runtime data |
| `*.sql`, `*.dump`, `*.sqlite`, `*.key`, `*.pem`, `secrets/` | data / secrets |
| `docker-compose.override.yaml` | local-only source mounts |
| `.ai/`, `.zcode/`, `.claude/`, AI workspace dirs | tooling state |
| `nul` (Windows artifact inside owner-console) | junk file — removed |

## Rules

1. **Private regression fixtures** may exist in the local repository for
   regression protection, but are excluded from any distribution artifact.
   They must remain self-skipping so CI without them stays green.
2. **No secret material** may ship in any form: keys, tokens, passwords,
   connection strings, dumps. Encrypted-at-rest operator secrets live only in
   the platform database (never in the artifact).
3. **No machine-specific assumptions**: absolute host paths, private IPs,
   private domains, fixed usernames. Runtime configuration comes from
   environment variables, the setup wizard, or safe defaults.
4. **Product docs must pass the private-reference scan** before release.
5. The **public history** is `CHANGELOG.md`; root-level `PHASE*.md` files never ship.
   Gate/acceptance reports that live under `docs/product/` are part of the public
   repository and DO ship — the packager's root-history exclude must stay ANCHORED
   (`./PHASE*.md`) so it never matches them at deeper levels (rc.2: it silently
   dropped every `docs/product/PHASE_*.md` from rc.1).
6. **Required 0.4.0+ release docs** that must exist in the repository AND in every
   built artifact (enforced by `scripts/tests/dist-docs.test.sh`):
   `docs/product/PHASE_ABC_GATE.md`, `docs/product/PHASE_D_GATE.md`,
   `docs/product/PHASE_E_GATE.md`, `docs/product/PHASE_F_GATE.md`,
   `docs/product/PHASE_I_GATE.md`, `docs/product/PHASE_J_GATE.md`,
   `docs/product/PHASE_IJK_FINAL_REPORT.md`, `docs/product/V040_RC1_RELEASE_REPORT.md`,
   `docs/product/RC1_ACCEPTANCE_REPORT.md`, `docs/ai/NEXUS_INSPECT_MODE.md`,
   `docs/ai/NEXUS_ACTION_SAFETY.md`, `docs/ai/NEXUS_COPILOT_ARCHITECTURE.md`.
