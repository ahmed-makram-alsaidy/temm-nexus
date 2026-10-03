# Release Notes — TEMM Nexus v0.4.0 (stable)

**Release date:** 2026-10-03
**Status:** STABLE
**Base:** `v0.4.0-rc.5` (frozen, accepted) — version metadata only
**Minimum upgrade-from version:** 0.1.0 (see docs/open-source/UPGRADE_ROLLBACK.md)

TEMM Nexus is an open-source, self-hosted backend migration & control plane.
v0.4.0 is its second stable release.

## New in v0.4.0 (since v0.3.0)

- **Workspace / client layer** — a real tenancy boundary between the
  platform and its clients' projects, with workspace overview, members and
  activity, and per-scope capability bundles (fail-closed, re-checked at
  every call).
- **Product information architecture** — redesigned Platform Home,
  Workspace and Project Overview around the migration journey; Cutover
  became its own readiness screen; infrastructure telemetry demoted to
  progressive disclosure.
- **Nexus AI** — scoped conversations (platform / workspace / project) with
  typed read-only tools, provider-agnostic TOOL_CALL/TOOL_RESULTS transport
  (OpenAI, Anthropic, Gemini, OpenRouter, OpenAI-compatible — BYOK),
  bounded model-role routing, and a hash-only audit ledger.
- **Settings → Nexus AI** (`/admin/nexus-ai-settings`) — production-facing
  AI configuration: provider selection, encrypted API keys with masked
  display (never re-displayed), optional model-role overrides (default
  assistant / deep diagnosis / code changes), a safe "Test connection"
  action, and a platform master switch. Gated to `ai.configure`
  (Platform Owner/Admin) and audited.
- **Inspect Mode** — select a registered UI component and attach it to the
  conversation; server-side registry + capability checks; Explain /
  Diagnose; no DOM ever leaves the browser.
- **Structured UI preferences** — whitelisted, typed, per-user appearance
  (visibility, density, expanded-by-default, position) behind an explicit
  preview → apply step.
- **Safe AI actions** — six narrow, permissioned mutations (pause/resume
  Live Sync, create backup, re-run validation, retry one failed job,
  cutover preflight) behind the full
  PLAN → HUMAN APPROVAL → APPLY → VERIFY chain with execution-time
  authorization re-checks, state-fingerprint staleness defense,
  idempotency and an AI-action audit trail. Arbitrary shell and arbitrary
  SQL remain registered nowhere.
- **New Project wizard** (`/admin/new-project`) — six guided steps
  (Project → Source → Connection → Destination → Analyze → Review) with
  visual source cards, connector-schema-driven connection fields with Test
  Connection, TEMM-managed destination as the default (external PostgreSQL
  under Advanced), read-only source analysis, and a dry-run Start
  Migration. Projects created through the wizard receive their
  `workspace_id`; the classic create form remains available.
- **Arabic + English as first-class languages** — runtime localization
  across the product surface with real RTL for Arabic (direction flip,
  Arabic typography rules, LTR-preserving technical values). Locale
  resolution: user preference → session → cookie → platform default
  (chosen during setup) → English. Visible language switcher on every
  surface, no rebuild, no logout.
- **Fake/test AI providers are hidden in production** (`AI_ALLOW_FAKE_PROVIDERS`),
  regression-tested.

## Migration semantics — read this

Migration capability is **low-downtime, not zero-downtime**: CDC capture
shortens the window, but the final cutover remains an operator-controlled
procedure with brief application downtime expected. The platform never
claims a guaranteed zero-downtime switch and never executes production
endpoint changes by itself.

## Changed

- Version promotion from `v0.4.0-rc.5` — content identical to rc.5; the
  codebase passed the full stable release closure (below).

## Security

- `composer audit`: 0 advisories for the shipped lockfile.
- Secret scan and distribution private-reference scan pass on the release
  tree and on the built artifact.
- Support bundle redaction verified (no secrets, no .env values, no
  customer data); AI prompt/action injection defenses regression-tested
  (tool-result injection never triggers an action; a fabricated approval in
  content executes nothing).

## Known limitations

- **Firebase CDC is DEFERRED** — the Firebase connector migrates/analyzes
  but does not capture live changes.
- **MariaDB is PARTIAL** — the MySQL connector is only separately proven
  on MySQL 8.0/8.4; MariaDB servers are unverified.
- MongoDB multi-document transactions apply without cross-document
  atomicity (convergent, idempotent).
- MySQL resume is file+position (GTID set recorded as evidence); the
  GTID-dump packet of the bundled binlog library is unusable on MySQL 8.0.
- TRUNCATE (PostgreSQL) and Mongo `INVALIDATE` pause capture and require
  an operator decision; they are never guessed through.

## Verification (stable closure, 2026-10-03)

- Hermetic blocking suite (`phpunit-release.xml`) on the release commit:
  **717 tests / 0 failures / 0 errors** (CI Linux run: 3946 assertions;
  22 env-guarded skips on Windows workstations — live-source probes and
  browser-driven setup tests).
- Localization gate: every English key exists in Arabic and vice versa
  (missing = 0 both directions), no empty translation files, Arabic
  pluralization resolves all forms, RTL/LTR direction rendering, locale
  persistence (user → session → platform default).
- AI regression: settings page capability gate, encrypted + masked API
  keys (never repopulated), fake-provider hiding in production, model-role
  routing, master switch, Inspect Mode (capability-checked registry,
  injection-stays-data, audited turns), Safe Actions approve/reject chain.
- New Project wizard regression: capability gate, workspace-scoped listing,
  full flow creating a project with workspace + vaulted source,
  TEMM-managed destination default, audited start.
- Artifact built from a clean checkout of the release commit; secret scan,
  private-reference scan and unintended-content scan clean; SHA-256
  computed twice and verified against the downloaded-back GitHub asset.
- Artifact-only fresh install (EN + AR, first admin, setup lock) and
  rc.5 → v0.4.0 upgrade drill with data preservation.
- Disposable acceptance VPS: HTTPS, Doctor, EN/AR switch + persistence,
  bounded CDC smoke (PostgreSQL WAL / MySQL binlog / MongoDB change
  streams), controlled reboot — recorded in the v0.4.0 stable release
  report.

## Upgrade notes

- Read `docs/open-source/UPGRADE_ROLLBACK.md`; the upgrader creates a
  pre-upgrade backup automatically.
- CDC sources need provider-side configuration/permissions: PostgreSQL
  logical replication (WAL level, replication role, publication), MySQL
  `log_bin` + `binlog_format=ROW` + REPLICATION privileges, MongoDB
  replica set. See `docs/connectors/REAL_CDC_RUNBOOK.md`.
