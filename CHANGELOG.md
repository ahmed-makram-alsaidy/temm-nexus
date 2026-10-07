## [0.6.1] — 2026-10-07

**PATCH.** Sync-state honesty fix: the journey never fabricates a running
Live Sync, and the TEMM-managed destination finally exposes the real-transfer
path the domain always supported. Found on the operator VPS: after a
completed 350/350 dry run on a Supabase source whose Review said
"Live Sync — Not supported by this connector", the Migration → Sync page
showed "Sync — In progress" with a LIVE SYNC panel reading
"Starting / Live Sync has not started." — a fake running operation this
connector can never perform.

### Fixed

- **Canonical Sync state** (`ProjectPulse::syncState()`) — with no CDC
  checkpoint, IN_PROGRESS ("Starting") now requires BOTH: the source
  connector declares change capture at all (the same fact the wizard Review's
  `selectedConnectorSupportsLiveSync()` resolves, so the Review verdict and
  the journey can never disagree), AND the latest run was a writing run
  (rehearsal/real — a dry run writes nothing and cannot hand off to Live
  Sync). A connector that cannot stream now sits honestly at NOT_STARTED —
  never a permanent fake "Starting"; a completed dry run no longer flips the
  Sync stage to "In progress".
- **Honest unsupported wording** (`ProjectPulse::liveSync()`) — a connector
  that definitively cannot stream says "Not supported by this connector" in
  the Review's own words, with a one-line teaching detail; an unresolvable
  source stays neutral ("Not running"). Propagates verbatim to the journey
  Sync tab, Project Overview, Home facts and the cutover gate (one
  interpretation layer).
- **Real-transfer path presented** (Phase E Sync tab) — `mode='real'`
  ("Live transfer (real target)") existed in the run manager's vocabulary
  (`MigrationRunManager::MODES`) and in the EN+AR dictionaries since Phase H,
  but no surface ever presented it and `startRun()` silently demoted every
  choice to dry_run/rehearsal. The managed destination's run form now offers
  Live transfer: the chosen mode is the mode that runs, targeting the
  TEMM-managed database, `target_disposable=false` (the managed destination
  is the real destination, never flagged disposable), reset stays
  rehearsal-only. The option is hidden where GUARD 1 would refuse the run
  anyway (active production environment); the enforcing guards themselves
  (production refusal, disposable-reset, source≠target) are unchanged and
  re-pinned by tests.

### Tests

- `tests/Feature/Phase61/SyncStateCanonicalTest.php` — five regressions:
  unsupported Live Sync renders "Not supported", never "Starting"; a
  completed dry run never fabricates a running Live Sync; the merged
  MIGRATE+SYNC tab state is canonical and deterministic (rehearsal→
  "Starting", failed stream→BLOCKED, streaming→COMPLETE); the TEMM-managed
  destination exposes and starts the real transfer against the managed,
  non-disposable target (failing closed against an unreachable target, no
  fake success); no unsafe transfer mode becomes available by mistake
  (option hidden under an active production environment; the run manager
  still 422s production for every mode).

## [0.6.0] — 2026-10-07

**STABLE.** The product experience release. Identical application code to
the accepted v0.6.0-rc.1 (release-closure metadata only); the rc.1 release
notes below describe the feature set in full and remain frozen as
historical evidence.

## [0.6.0-rc.1] — 2026-10-07

**PRE-RELEASE — release candidate.** The product experience release: a
light-first redesign of the whole operator surface, Projects as the product's
center of gravity, a guided migration journey, a chat-first Nexus AI with
durable conversations, and a status-first Developer Agent workbench. Fully
additive schema (two migrations); existing v0.5.0 installations upgrade in
place with no data loss. Full feature set in
`docs/open-source/RELEASE_NOTES_0.6.0-rc.1.md`.

## [0.5.0] — 2026-10-04

**STABLE.** First stable release of the Agent Runtime Platform. Identical
application code to the accepted v0.5.0-rc.1 (release-closure metadata
only); the rc.1 release notes below describe the feature set in full and
remain frozen as historical evidence.

## [0.5.0-rc.1] — 2026-10-04

**PRE-RELEASE — release candidate.** The Agent Runtime Platform: a new
"Developer Agent" capability on the `develop/0.5.0` branch, with OpenCode as
the first supported runtime. Tasks run in isolated git worktrees, produce
deterministic fingerprint-bound changesets, and never touch authoritative
source without an explicit human approval. Everything below is additive;
existing 0.4.0-rc.7 work on `develop/0.4.0` is preserved unchanged.

### Added

- **Runtime abstraction layer** (`App\Services\Agent`) —
  `AgentRuntimeContract`/`AgentRuntimeManager` (driver registry, the only
  place a runtime name is resolved), normalized
  capabilities/connection/model/event DTOs, structured error categories
  (`RUNTIME_UNAVAILABLE`, `RUNTIME_AUTH_FAILED`, `MODEL_UNAVAILABLE`,
  `SESSION_FAILED`, `WORKSPACE_FAILED`, `COMMAND_FAILED`, `TASK_CANCELLED`,
  `INVALID_RUNTIME_RESPONSE`, `TIMEOUT`), the v1 `AgentExecutionPolicy`
  (read/write/execute inside the assigned workspace only; apply requires
  approval; deploy denied) and `AgentNetworkGuard` (external endpoints
  HTTPS-only, private/metadata ranges refused).
- **OpenCode adapter** (`Runtimes\OpenCode`) — HTTP transport for the
  official server API verified against OpenCode 1.18.34's own OpenAPI
  schema (`docs/agent-runtime/OPENCODE_INTEGRATION.md`): health/version
  gate (MAJOR 1, MINOR ≥ 18), live provider/model discovery (ids verbatim),
  per-directory session creation (`?directory=`), official session
  permission ruleset (external-directory DENIED), async prompts
  (`parts` shape), SSE event streaming with idle/total caps, permission
  replies under platform policy, per-session diff, abort. Event normalizer
  enforces the privacy boundary: hidden reasoning is never surfaced, command
  output is reduced to byte counts.
- **Workspace isolation** (`AgentWorkspaceService`) — unique disposable
  `git worktree` per task under `storage/app/private/agent-workspaces`,
  realpath containment, traversal/absolute/`.git`/symlink-escape guards,
  retention sweep, deletion confined to the workspace root.
- **Changeset / approval / apply / verify** — deterministic workspace
  `git diff --binary` changesets with SHA-256 fingerprints; single-use
  fingerprint- and revision-bound approvals; apply via whole-patch
  `git apply --check` + apply with a stash recovery snapshot recorded;
  STALE (source moved) and TAMPERED (content changed) refusals; oversized
  changesets require manual review; per-project verification policy
  (operator-defined, never agent-proposed, deploy excluded) recorded
  honestly (`passed`/`failed`/`error`/`skipped`).
- **Task orchestration** (`AgentTaskService` + `RunAgentTask` job on the
  new Horizon `supervisor-agents` queue) — bounded event ledger (500
  events), command ledger (metadata only), usage capture, concurrency
  limits, cancellation (in-flight abort included), truthful outcomes (an
  empty result is never reported as success).
- **Schema** — additive Phase 43 migration: `agent_runtimes`,
  `agent_tasks`, `agent_workspaces`, `agent_task_events`,
  `agent_task_commands`, `agent_changesets`, `agent_approvals`,
  `agent_verifications`.
- **Permissions** — new `agents.view/run/configure/cancel/approve/apply/
  audit` capabilities mapped across platform/workspace/project scopes
  (`agents.configure` is platform-only); audit actions
  `AGENT_*` in `AdminAuditEntry`.
- **Web UI** — Settings → Developer Agents (runtime CRUD, managed/external,
  write-only secrets, real connection test, live model discovery) and the
  Developer Agent workbench (task creation, live polling of activity,
  commands, diff, approval, apply, verification); full EN/AR with true RTL
  and LTR-preserved technical identifiers.
- **CLI** — `agent:status`, `agent:runtimes`, `agent:models`,
  `agent:run`, `agent:show`, `agent:diff`, `agent:approve`, `agent:apply`,
  `agent:cancel`, `agent:verify` — the same service layer as the web.
- **Managed OpenCode service** — pinned image build
  (`infrastructure/docker/opencode`, official `opencode-ai` npm package,
  non-root, gosu drop) added to dev and prod compose as an internal-only
  service (no published ports, not routed by Caddy) sharing exactly the
  isolated-workspace path; `OPENCODE_SERVER_PASSWORD` required.
- **Tests** — 3 unit + 9 feature Phase 43 suites (76 tests): runtime
  registry, event normalization/SSE parsing, policy, workspace isolation
  (traversal/symlink/VCS guards), full mock-runtime lifecycle
  (run→diff→approve→apply→verify, cancel, timeout, permission reply,
  structured failures), security suite (SSRF, replay, tamper, stale,
  cross-project, oversized, secret-leakage scans), transport wire proofs,
  settings/UI authorization, CLI flows, EN/AR parity gates. Hermetic
  release suite green (823 tests at introduction).
- **Session filesystem isolation (final hardening)** — every session shell
  runs through a Landlock (LSM) wrapper (`ll-sh`, baked into the managed
  image): fail-closed outside the workspaces root, scrubbed environment,
  filesystem grants only for the session workspace + its worktree gitdir
  (common store read-only), system paths, /proc, /dev, /tmp. Verified on
  the disposable VPS: sibling-workspace enumeration, cross-project reads,
  runtime-secret reads (file AND environment) and app_storage access are
  all denied inside a real model session, while `git status` keeps working
  and the full run→diff→approve→apply→verify lifecycle is unchanged.
- **Docs** — `docs/agent-runtime/OPENCODE_INTEGRATION.md` (Gate B record)
  and `docs/agent-runtime/DEVELOPER_AGENT.md` (operator manual).

## [0.4.0-rc.7] — 2026-10-03

**PRE-RELEASE — release candidate.** AgentRouter transport closure: the
custom-header / client-identification feature, safe wire-level transport
telemetry, and the Test Connection probe fix. The API key, endpoint and
model configuration are untouched; this changes only HOW the transport is
built, observed and classified.

### Added

- **Safe wire-level telemetry for every AI provider call**
  (`TransportTelemetry` + the `nexus-ai` log channel,
  `NEXUS_AI_TELEMETRY`/`NEXUS_AI_TELEMETRY_CHANNEL`): final URL, HTTP
  method, status code, response Content-Type, effective User-Agent, request
  JSON FIELD NAMES and the `stream` value — for both Test Connection and
  real chat. Header VALUES are logged only for User-Agent/Content-Type/
  Accept; `Authorization` is always `[protected]`, every other custom header
  value `[redacted]`, non-2xx body previews have the configured key
  redacted, and the API key is structurally excluded from every entry.
  Regression-covered by `TransportTelemetryTest` (including a leak probe).
- **Raw-wire regression for the effective User-Agent** — a loopback socket
  capture server (`tests/Feature/Phase42/Fixtures/`) drives the real driver
  beyond `Http::fake()` and asserts the outgoing wire header is exactly
  `codex_cli_rs/0.149.1` on BOTH `test()` and `complete()`, that Guzzle
  stamps its own `GuzzleHttp/7` default only when no custom UA is
  configured, that the credential header appears exactly once, and that the
  request JSON shape is `model/messages/max_tokens/stream`.
- **AgentRouter acceptance runner** — `scripts/agentrouter-acceptance.php`
  runs the five acceptance gates (HELLO visible / Test Connection /
  normal reply / no blank bubble / secret leakage 0) against the configured
  provider row through the production code paths and prints the transport
  comparison block from the safe telemetry. `--mock` runs the identical
  flow fully offline against the new `scripts/mock-agentrouter.php` gateway
  stub (which refuses non-codex User-Agents exactly like the real router).

### Fixed

- **Header merge order is now an explicit contract**: framework defaults
  first, validated custom provider headers LAST (so a gateway that
  identifies clients by User-Agent receives the configured value), and the
  vault credential re-asserted AFTER the merge (Authorization can never be
  displaced by stored state).
- **Test Connection no longer misclassifies a starved reasoning model.**
  The probe sent `max_tokens: 1`; reasoning-style models burn the whole
  budget on reasoning and answer HTTP 200 with EMPTY `content`, which the
  old classifier reported as PROVIDER_ERROR — exactly the observed
  "Connection failed / The provider returned an error." on a router where
  the same key/endpoint/model works from codex_cli_rs. The probe now sends
  a small sane budget (≤ 32) and treats `finish_reason: "length"` as
  CONNECTED (auth, model and pipeline proven); real chat still refuses
  empty content.
- Every wire call now sends `"stream": false` explicitly, so the request
  shape is identical across providers regardless of their defaults.
- `AiNetworkGuard` gained a local-development-only allowance
  (`AI_ALLOW_LOOPBACK_ENDPOINTS=true`, default false) so the acceptance
  flow can run against the mock gateway offline; production behavior is
  byte-for-byte unchanged and regression-tested.

## [0.4.0-rc.6] — 2026-10-03

**PRE-RELEASE — release candidate.** One fresh-install bootstrap fix on
top of the accepted v0.4.0-rc.5 (which stays frozen and published). Found
by the v0.4.0 stable-closure artifact-only fresh-install gate; v0.4.0
stable remains unpublished pending this RC's acceptance.

### Fixed

- **A fresh install had no UI path to create its first workspace
  (client).** The Clients & Workspaces page is the only place workspaces
  are created, but its entry check required reaching at least one
  workspace — so the page returned 403 on a fresh install and the
  New Project wizard (which requires an existing workspace) could not
  start. Entry is now also granted to holders of the `workspaces.create`
  platform capability (the Platform Owner); everyone else stays
  fail-closed. Regression-tested (`WorkspaceBootstrapAccessTest`).

## [0.4.0-rc.5] — 2026-10-03

**PRE-RELEASE — release candidate.** Product UX closure on top of the
accepted v0.4.0-rc.4 (which stays frozen and published): Nexus AI settings,
a guided New Project wizard, and first-class Arabic/English localization.
No unrelated features; rc.4 is not modified.

### Added

- **Settings → Nexus AI** (`/admin/nexus-ai-settings`) — a real,
  production-facing AI configuration page: current status (enabled,
  provider, model, last test, last safe error), provider configuration for
  OpenAI / Anthropic / Gemini / OpenRouter / OpenAI-compatible, encrypted
  API keys with masked display (never re-displayed), an optional model-role
  override section (default assistant / deep diagnosis / code changes —
  one provider + one model still works), a safe "Test connection" action,
  and a visible platform-default vs project-scoped scope statement.
  Gated to `ai.configure` (Platform Owner/Admin) and audited.
- **New Project wizard** (`/admin/new-project`) — six guided steps
  (Project → Source → Connection → Destination → Analyze → Review) with
  visual source cards from the connector registry, connector-schema-driven
  connection fields with Test Connection, TEMM-managed destination as the
  obvious default (external PostgreSQL under Advanced), read-only source
  analysis summarized in product language, a plain-language readiness
  review, and a dry-run Start Migration. Replaces "New project" entry
  points on Projects and Workspace pages; the classic create form stays
  reachable for power users. Projects created through the wizard now
  actually receive their `workspace_id` (a known 0.4.0 gap).
- **Arabic + English as first-class languages** — a runtime localization
  layer (`lang/en`, `lang/ar`) across the product shell, navigation, Home,
  Workspaces, connector catalog, Nexus AI, the setup wizard, the New
  Project wizard, error pages, and the landing page. Arabic gets real RTL:
  the panel direction flips via locale, Arabic typography rules (no
  letter-spacing on cursive text, Arabic font stack), LTR-preserving code
  values, and mirrored directional glyphs. English remains LTR.
- **Language switcher + persistence** — a visible selector in the product
  shell (and on the login/setup/landing surfaces for guests). Resolution
  order: user preference (`users.locale`, additive migration) → session →
  cookie → platform default (`platform.locale`, chosen during first-run
  setup step 2) → English. No rebuild, no logout.
- **Nexus AI follows the UI locale** (C.14) — the assistant's system
  prompt carries a LANGUAGE directive derived from the active locale;
  tool outputs stay canonical (presentation-only translation).
- **Translation completeness gate** (Part G) —
  `tests/Feature/Phase41/TranslationCompletenessTest.php` fails the build
  on any key present in one language but missing in the other, asserts the
  two file sets match, and verifies Arabic pluralization across all six
  Arabic plural forms.

### Fixed

- **Fake/test providers can no longer surface in a production UI** (A.7) —
  the `fake` provider is hidden from the AI settings page, the project
  Copilot provider picker, and ModelRouter routing unless
  `AI_ALLOW_FAKE_PROVIDERS` explicitly allows it (default: allowed outside
  production). Regression-covered in `FakeProviderVisibilityTest`.
- **The `env` core-container binding was depended on at config-load time**
  — `config/nexus-ai.php` reads `APP_ENV` via `env()` because this
  framework version no longer registers `env` as a container alias and
  `app()->isProduction()` in a config file fatals `artisan`/tests with
  "Target class [env] does not exist".
- **`ModelRouter::routingTable()` fatals when no provider is configured**
  ("Trying to access array offset on null") — the Settings page renders
  the routing table before any provider exists; the table now degrades to
  an "Unconfigured" row.
- **`POST /locale` was trapped by the first-run gate** — the language
  switch must work before a user or platform default exists, so the gate
  allowlists the locale endpoint.
- **Guest surfaces (setup wizard, login, landing) get localized
  `<html lang dir>`, Arabic font stacks, and a language switcher.**

### Localization closure (final rc.5)

- **Every normal authenticated product surface now ships Arabic**: a
  450-string audit of all 60 Filament pages/resources/widgets converted
  deep-page titles, action labels, modal headings/descriptions, helper
  texts, placeholders, notifications, and `return`-style getters to
  translation keys (437 `labels.*` keys + component catalogue), covering
  Database, Schema, ERD (including the canvas JS via injected i18n), SQL
  Editor, Functions, Table Editor, Migration Center, CDC/Validation/
  Cutover details, Backups, Logs, Audit, Infrastructure, Security,
  Operations, and project/workspaces settings. Technical identifiers
  (PostgreSQL, WAL, HTTP, PROMOTE, env-var names, vendor names) stay
  canonical per C.10.
- **Inspect Mode component catalogue** localizes labels/descriptions at
  READ time (`lang/{en,ar}/components.php`); the registry const keeps a
  compile-time-safe English fallback (a `__()` call inside a class const
  compiles to a hard process death — found by the localization matrix).
- `NoUntranslatedStringsTest` — a regression gate that fails the build on
  any NEW hard-coded user-visible string in the product surface, with a
  technical-identifier allowlist.
- **Inline deep-page tables localized**: the ~200 hard-coded `<th>` column
  headers across 40+ pages (Logs, Migrations, Realtime, Scheduler,
  Webhooks, Backups, Storage, API Keys, Infra, Team, …) now render through
  `labels.th_*` keys; the Logs Explorer toolbar, filter options and
  sources note are localized.

### Tests

- `tests/Feature/Phase41/` — 50 new tests: locale resolution order,
  RTL/LTR direction on the panel, session/cookie/user persistence,
  platform default, Arabic pluralization, translation-key parity,
  AI settings authorization + API-key encryption/masking + safe test
  results + role routing + master switch, fake-provider visibility,
  wizard flow/validation/tenant isolation/vault handling, and AI locale
  propagation without authorization changes.

## [0.4.0-rc.4] — 2026-10-02

**PRE-RELEASE — release candidate.** Two hotfixes on top of the published
v0.4.0-rc.2, cut under the release-correction policy after the VPS
acceptance found release-blocking defects (rc.2 stays frozen and published;
this supersedes it).

### Fixed

- **Home and Project Overview returned HTTP 500 for any project whose CDC
  checkpoint carried a last-event time.** `ProjectPulse::checkpoint()`
  reads the checkpoint row through the query builder, so `last_event_at`
  arrived as a raw string and `syncLagSeconds()` called `->diffInSeconds()`
  on it (found live on the operator test VPS during rc.2 acceptance).
- **The Home attention list fataled when a blocked transfer run carried a
  structured failure payload** (`failure` is cast to an array on the model
  and `mb_substr()` received it — same real-data class, same VPS acceptance
  pass). Both shapes now reduce to readable text, with regressions for
  each in `ProjectPulseCheckpointTest`.

## [0.4.0-rc.2] — 2026-10-02

**PRE-RELEASE — release candidate.** Narrow polish/packaging release on top
of the accepted v0.4.0-rc.1 (which stays frozen). Exactly three fixes, no
new features.

### Fixed

- **The Inspect toggle overlapped the Nexus AI launcher.** With a long
  scope label ("Project — Client One Website") the absolutely positioned
  launcher grew leftward under the fixed Inspect toggle, hiding it and
  intercepting its clicks. Both controls now live in one floating flex
  container (`nx-floating-controls`) — no overlap and no click interception
  at any viewport width, with Inspect on or off; the launcher's scope label
  truncates instead of colliding.
- **The artifact silently dropped `docs/product/PHASE_*.md`.** The
  root-history exclude `PHASE*.md` was unanchored and matched at every path
  level, removing the 0.4.0 gate reports from the rc.1 artifact (found by
  the rc.2 dist-docs test). The exclude is now anchored to the repo root,
  the allowlist names the required 0.4.0 docs, and
  `scripts/tests/dist-docs.test.sh` verifies their presence in every built
  artifact. The packager also no longer masks transient tar failures with
  `|| true` — it retries and fails loudly.
- **"1 project need attention"** now reads "1 project needs attention" /
  "2 projects need attention" — the verb agrees with the count.

## [0.4.0-rc.1] — 2026-10-02

**PRE-RELEASE — release candidate.** The 0.4.0 product transformation,
Phases A–K. `v0.3.0` is untouched.

### Added

- **Workspace / client layer** — a real tenancy boundary between the
  platform and its clients' projects, with workspace overview, members and
  activity, and per-scope capability bundles (fail-closed, re-checked at
  every call).
- **Product information architecture** — redesigned Platform Home,
  Workspace and Project Overview around the migration journey; Cutover
  became its own readiness screen; infrastructure telemetry demoted to
  progressive disclosure.
- **Nexus AI** — scoped conversations (platform / workspace / project) with
  17 typed read-only tools, provider-agnostic TOOL_CALL/TOOL_RESULTS
  transport (OpenAI, Anthropic, Gemini, OpenRouter, OpenAI-compatible —
  BYOK), bounded model-role routing, and a hash-only audit ledger.
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

### Fixed

- The Cutover screen no longer 500s for projects with a CDC stream
  (gate details may be structured; found by the Phase K UX review).
- MySQL 8.4 compatibility for binlog CDC (SHOW BINARY LOG STATUS with a
  fallback for 8.0), found by the Phase K live smoke.
- The blocking release suite is hermetic on phpredis-less hosts
  (skip-clean; CI runs the suite with real Redis).

### Notes

- Firebase CDC remains DEFERRED. MariaDB remains PARTIAL.
- MongoDB: change streams proven live on the artifact; inherited snapshot
  edge cases with certain document shapes are documented in the release
  report (byte-identical engine to v0.3.0, whose own proof stands).
- Known limitations are stated in `docs/product/PHASE_IJK_FINAL_REPORT.md`.
>>>>>>> origin/main
## [0.3.0] — 2026-10-01

**First STABLE release.** Open-source self-hosted backend migration &
control plane. Content identical to `v0.3.0-rc.3` (version metadata only —
the rc.3 codebase passed the full stable closure: real UI smoke, PostgreSQL
WAL / MySQL binlog / MongoDB change-stream CDC with lost=0 duplicates=0
delta=0, cutover gates, backup/restore, reboot persistence, hermetic
blocking suite, artifact-only fresh install, rc.3 → stable upgrade drill).

### Changed

- Version metadata promoted from `0.3.0-rc.3` to `0.3.0`.

## [0.3.0-rc.3] — 2026-10-01

**PRE-RELEASE — release candidate.** Release-closure fix found during the
rc.2 stability soak (Phase 36.5). No product features.

### Fixed

- **Connector Catalog page rendered HTTP 500 for every admin.** Two
  defects, found live through the real browser on the Azure rc.2 soak:
  `Table::columns()` received a Closure (this Filament version requires
  an array), and the page's `render()` override bypassed the Filament
  panel page lifecycle so Livewire fell back to the missing default
  `layouts.app`. The page now declares its view like every other page;
  a feature regression asserts the page renders (fails against rc.2).

## [0.3.0-rc.2] — 2026-10-01

**PRE-RELEASE — release candidate.** Release-closure fix on top of the
frozen v0.3.0-rc.1. Scope limited to the upgrade edge-configuration bug
and its regression coverage — no product features.

### Fixed

- **Upgrader: Caddy edge configuration was not refreshed on upgrade.**
  `docker compose up -d` recreates a container only when its service
  definition changes; the content of a bind-mounted file is invisible to
  compose, and Caddy reads its Caddyfile once at container start — so an
  upgrade that shipped a changed `Caddyfile.selfhost` left the OLD edge
  routing active while the upgrade reported success (reproduced locally
  and hit live upgrading the Azure test VM to rc.1: 404s until caddy was
  recreated by hand). The upgrader now fingerprints the Caddy-relevant
  inputs (Caddyfile + the env values it interpolates) and force-recreates
  ONLY caddy when that fingerprint changes; unchanged configuration is
  not recreated, and the caddy TLS volumes are untouched. The installer
  records the same fingerprint on fresh installs. A CI regression
  (fresh-install job) changes the Caddyfile, upgrades and asserts the
  new configuration is served, then upgrades unchanged and asserts caddy
  was NOT recreated.

## [0.3.0-rc.1] — 2026-10-01

**PRE-RELEASE — release candidate.** This is release-candidate software;
breaking changes may still land before stable 0.3.0. The 0.3.0 line opened
on `develop/0.3.0` from `v0.2.0-rc.3`; features below are documented as
SUPPORTED / PARTIAL / DEFERRED in `docs/connectors/PHASE_29_TO_32_CONNECTORS.md`.

Migration capability in this release is **low-downtime**, not a guaranteed
zero-downtime migration: CDC capture shortens the window, but the final
cutover is operator-controlled with documented prerequisites and
limitations (`docs/connectors/REAL_CDC_RUNBOOK.md`). Known scope limits:
Firebase CDC is **DEFERRED**; MariaDB is **PARTIAL** unless separately
proven; CDC sources require specific server configuration/permissions
(logical replication, row binlog, replica set).

### Added

- **Phase 35.6 — real log-based CDC** (PostgreSQL WAL logical decoding,
  MySQL row-based binlog, MongoDB change streams): provider-native captures
  behind the generic 32A contract with normalized events (before/after
  images, source timestamps, transaction identity), signed provider-native
  positions (LSN / binlog file+pos with GTID evidence / server resume
  tokens), at-least-once delivery with idempotent convergence, transaction
  boundaries preserved, DELETE support on all three providers, project+run
  scoped slot lifecycle with cleanup, normalized lag telemetry and the REAL
  cutover gates (PASS/WARN/BLOCK/UNVERIFIED — watermark checkpoints never
  pass), the final-delta freeze-boundary workflow (DATA_READY_FOR_CUTOVER),
  and `migration:cdc-capture` as the operator entry point.
  Proven live on disposable PostgreSQL 17 / MySQL 8.0 / MongoDB 7 replica
  set: I/U/D + transaction batches, reader kill + resume, source restarts
  (binlog rotation included), tamper refusal, schema-drift pause with
  operator remediation, 155k-event storm with zero loss and bounded memory
  (~840 events/s capture+apply on 2 vCPU). See
  `docs/connectors/REAL_CDC_RUNBOOK.md` for the operator contract.
- **Phase 29 — Firebase connector** (`firebase`, first_party): read-only
  Firestore analysis with inferred schema, relationship candidates with
  confidence classes, deterministic batched extraction, Firebase Auth
  inventory (no password material; password portability always
  NEEDS_REVIEW), Storage inventory with copy strategies, Cloud Functions
  inventory/classification, client repository scanning
- **Phase 30 — generic PostgreSQL connector** (`postgres`, first_party):
  import any standard PostgreSQL database; pg_catalog-native inspection
  with exact type preservation; read-only sessions enforced and verified;
  keyset extraction; unknown extension types flagged NEEDS_REVIEW
- **Phase 31 — MySQL/MariaDB connector** (`mysql`, first_party):
  unsigned-safe deterministic type widening, AUTO_INCREMENT state
  preservation, charset analysis with transcoding review, scheduled-event
  inventory
- **Phase 32 — change capture foundation**: generic CDC contract,
  HMAC-signed tamper-safe checkpoints, idempotent upsert/delete event
  application on the migration targets, read-only provider readiness
  probes (PostgreSQL logical replication, MongoDB change streams, MySQL
  binlog; Firebase delta DEFERRED), watermark-based incremental export
- **Phase 33 — AI client code migration**: deterministic conversion
  planning (supabase-js/dart, firebase-js/dart), approval-gated patch
  workspace integration, SECRET_PRESENT redaction in AI prompts,
  allowlisted test-command loop, diff-quality metrics
- **Phase 34 — Cutover Center**: project-scoped control room with honest
  gate states (PASS/WARN/BLOCK/NOT_APPLICABLE/UNVERIFIED), ordered cutover
  plans, explicit per-gate approvals, rollback plan with expiry, full
  audit; the platform never executes DNS/endpoint/production changes
- **Phase 35 — connector marketplace foundation**: package install
  lifecycle (inspect → verify checksums → install → enable → remove),
  extended trust vocabulary (first_party/trusted/community/private/
  unverified), publisher metadata, malicious-package rejection tests,
  Connector Catalog screen (local/catalog-backed; no billing)

### Fixed

- **Production asset pipeline:** frontend assets (Filament CSS/JS/fonts,
  Livewire) are generated during production builds — a fresh production
  install no longer serves an unstyled console (found on the live Azure VPS
  deployment)
- **Docker/runtime:** production image ships `pdo_mysql`; scheduler
  heartbeat age is reported unsigned in Deployment Doctor; the Pulse
  check daemon no longer starves the schedule loop, healthcheck 200
  detection and installer dry-run banner fixed
- `security-check.sh` self-match: `docs/SECURITY_HARDENING.md` quoted the
  scanner's own patterns, tripping the repo hygiene gate
- Migration engine: auth identity target table is collision-aware — a
  source with BOTH an auth domain and a `users` data table no longer
  breaks rehearsals (reserved `auth_users` target table)

## [0.2.0-rc.3] — 2026-09-27

Publication hardening on top of 0.2.0-rc.2 (same product source; release
tooling and repository fixes surfaced by the first public CI run).

### Fixed

- Line-ending hygiene: repository text files normalized to LF (CRLF in
  `VERSION` corrupted artifact filenames, and CRLF in `.env.example`
  broke scripted `APP_KEY` generation in CI)
- Restored `apps/owner-console/bootstrap/cache/` structure file — the
  directory is required by `artisan package:discover` on fresh clones
- CI: the PHP SDK test invocation passes its test path (PHPUnit exits
  with its usage screen when neither a config nor a path is given)
- CI: compose configuration validation now creates `.env` from
  `.env.example` first (the production compose requires it)
- CI: the fresh-install smoke now runs the real installer
  (`scripts/install.sh`) instead of a hand-rolled `.env` + compose
  sequence — the installer's database provisioning step
  (`ensure-platform-db`) is required and was being skipped, so
  migrations failed with a password-authentication error
- Added `storage/framework/{cache,sessions,views}` and `storage/logs`
  structure files so fresh clones can run artisan/PHPUnit (Laravel
  requires these directories; "Please provide a valid cache path")
- **Installer APP_KEY fix:** `scripts/install.sh` generated a 48-byte
  key (`rand -hex 24` piped through base64); Laravel requires exactly
  32 bytes, so every request failed with "Unsupported cipher or
  incorrect key length" after a fresh install. The installer now uses
  `openssl rand -base64 32`.
- Marked the CI test-suite job non-blocking with a documented reason:
  part of the suite requires operator-workstation project fixtures and
  is not yet hermetic (see CONTRIBUTING.md)

# Changelog

All notable changes to the platform are documented here. The public history
begins at 0.1.0-rc.1; earlier internal development history is intentionally
not part of the public record.

Format: [Keep a Changelog](https://keepachangelog.com/) · Versioning: [SemVer](https://semver.org/).
This project has not reached 1.0 — minor versions may contain breaking changes.

## [0.2.0-rc.2] — 2026-09-27

The first **public** release candidate of TEMM Nexus. Publication
preparation on top of the frozen 0.2.0-rc.1 artifact state (rc.1 remains
frozen locally and is superseded by this release).

### Added

- `LICENSE` — GNU Affero General Public License v3.0 (`AGPL-3.0-only`)
- `COPYRIGHT.md` and `TRADEMARKS.md` (ownership + brand usage policy)
- GitHub issue templates (bug report, feature request, connector
  proposal) and a pull request template
- TEMM Nexus branding: README, default `PLATFORM_NAME`/`BRAND_NAME`,
  package metadata

### Changed

- Publication sanitization: internal dogfood project names in test
  fixtures, docs and release tooling replaced with generic demo names;
  removed a local debug output file and a disposable local env file from
  the distribution surface
- The distribution private-reference scanner was rewritten with
  public-safe, working patterns

### Fixed

- Private-reference scanner case-insensitive patterns never matched
  (`(?i)` inline flags are not supported by `grep -E`); the scan is now
  effective

## [0.2.0-rc.1] — 2026-09-27

Local release candidate closing the 0.2.0 development line (Phase 28.1).
Built from the packaged artifact state, dogfooded artifact-only on disposable
stacks, and frozen locally. NOT published. 0.1.0-rc.2 remains frozen and
untouched alongside it.

### Fixed (found during Phase 28.1 artifact-only dogfood)

- **Fresh-install blocker (28.1C):** the Phase 27 migration backfilled
  `connector_key` with MySQL backtick quoting — a syntax error on a fresh
  PostgreSQL install (the SQLite test suite never caught it). Backfill is now
  driver-aware; a regression guard test scans all migrations for unscoped
  backtick identifiers in raw SQL.
- **Clean rehearsal integrity (28.1G):** the run-level `reset` flag never
  reached plan items, so the second clean-rehearsal pass duplicated
  child-table rows while the determinism verdict compared only run statuses —
  a false "deterministic". The reset now applies to every table item and the
  determinism check compares per-item written rows; the SQLite target's
  truncate no longer assumes `sqlite_sequence` exists.
- **Migration Center page-down (28.1E):** an invalid heroicon name
  (`arrow-path-round-square`) crashed the page with a 500 whenever a plan
  existed; corrected, plus an icon-availability audit of all referenced
  heroicons.
- **Setup wizard honesty (28.1C):** the Reverb check read the wrong config
  path and reported realtime as "not set" whenever it was configured.
- **Support bundle (28.1O):** the bundle now includes a sanitized connector
  inventory (keys, versions, trust, capabilities, enabled) — no connection
  strings or credentials.

## [0.2.0-dev] — 2026-09-27

Local development candidate (Phase 27 Connector SDK + Phase 28 MongoDB
connector). Not published. 0.1.0-rc.2 remains the frozen release candidate.

### Added (Phase 28 — MongoDB connector)

- **MongoDB source connector** (\`app/Connectors/Mongodb\`) built ONLY on the
  Phase 27 Connector SDK — zero MongoDB-specific branching in platform core.
- **Pure-PHP wire protocol client** (OP_MSG over TCP/TLS, batched cursors,
  SCRAM-SHA-256/1 auth) — no ext-mongodb, no system-wide tooling; a hard
  read-only command allowlist makes source writes structurally impossible.
- Probabilistic schema inference (bounded sampling, type frequency, confidence,
  SCHEMA_VARIANCE flags, depth cap 8), relationship candidates with
  EXPLICIT/HIGH_CONFIDENCE/POSSIBLE classification, index + $jsonSchema
  validator analysis, GridFS bucket detection, deterministic per-collection
  mapping strategies (RELATIONAL_TABLE / JSONB_DOCUMENT / HYBRID with derived
  child tables for arrays of objects).
- Exact Decimal128 (BID) codec — values never routed through float.
- AI-assisted mapping via the GENERIC \`recommend_mapping\` Copilot action
  (structure-only context: paths/types/counts — never raw documents).
- MongoDB client scanner patterns (Node driver, Mongoose, Prisma, PyMongo,
  Laravel MongoDB) through the generic ClientScannerProvider contract.
- Real-container dogfood: synthetic shop database on mongo:7 migrated through
  analysis → plan → rehearsal into a disposable PostgreSQL 17 target with
  Decimal exactness, Arabic UTF-8 roundtrip, FK integrity and source
  fingerprint immutability verified.
- docs/connectors/mongodb/ — 21 documentation files + implementation-status
  update of MONGODB_CONNECTOR_DESIGN.md.

- **Generic Connector SDK** — contracts (\`Connector\`, \`SourceConnector\`,
  account-discovery/analysis/extraction/validation refinements, \`ClientScannerProvider\`),
  stable capability vocabulary (16 capabilities, 5 honest statuses), declarative
  credential schemas (account/source/configuration scopes), and a manifest-
  driven package model (\`connector.json\`, schema version 1) with strict
  validation and a platform-compatibility gate.
- **Connector registry** — duplicate-key rejection, enable/disable, unknown-
  connector graceful failure, first-party package discovery under
  \`app/Connectors/*\`; onboarding, Migration Center and the migration runner
  resolve all providers through it (provider branching removed from core).
- **Sandboxed host services** — scoped secret resolution (vault-backed,
  operation-minimized), SSRF-guarded outbound HTTP, traversal-guarded project
  file reader, redacting structured connector logger, scoped artifact writer.
- **Supabase connector** (\`app/Connectors/Supabase\`) — the Phase 25 Supabase
  logic extracted behind the SDK with behavior preserved (PAT account flow,
  read-only PostgreSQL adapter, capability probe, client scanner patterns).
- **Example JSON connector** (\`app/Connectors/ExampleJson\`) — local dataset
  source with schema inference, batched extraction, relationship validation;
  full analyze → plan → migrate → validate rehearsal without Supabase code.
- **Connector developer CLI** — \`connector:list\`, \`connector:inspect\`,
  \`connector:test\` (contract battery incl. secret-leak canary),
  \`connector:make\` (scaffolds trust=unverified, disabled by default).
- **Connector SDK documentation** — docs/connectors/ (12 docs incl.
  BUILD_YOUR_FIRST_CONNECTOR and the Phase 28 MONGODB_CONNECTOR_DESIGN).
- Phase 27 traceability — connector key/version + analysis version stamped on
  sources, analyses and artifacts; lifecycle removal preserves migration
  history and revokes connector secret references.

## [0.1.0-rc.2] — 2026-09-27

Local release-closure candidate (verification hardening). Not published.

### Added

- **Deployment doctor** (`php artisan platform:doctor`) — 24 deployment
  checks with PASS/WARNING/FAIL/NOT_CONFIGURED/NOT_APPLICABLE statuses,
  documented exit codes (0/1/2), secret-safe output, and run history
  (`platform_doctor_runs`).
- **Support bundle** (`php artisan platform:support-bundle`) — sanitized
  diagnostic ZIP (doctor output, migrations, queue, backups, runtime info,
  redacted error summaries) with a zero-secret-leakage guarantee backed by a
  planted-secret regression test.
- Scheduler heartbeat surfaced to the doctor (detects a down scheduler).
- `SUPABASE_MANAGEMENT_API_URL` override for LOCAL/Supabase-compatible
  stacks (guarded: HTTPS required for public hosts in production).
- Release manifest generator (`scripts/release/manifest.sh`) — version,
  build date, schema migrations, supported runtimes, artifact SHA-256.

### Fixed

- **Multi-install isolation**: production compose no longer pins fixed
  network/volume names — independent installations no longer share a
  database volume (found by the fresh-install repeatability test).
- **Distribution packaging**: anchored exclusions — an unanchored
  `projects` pattern silently dropped every path containing a `projects`
  component (including the project workspace views) from release artifacts.
- **Project creation via UI**: blank optional timezone/locale crashed on a
  NOT NULL constraint; form defaults + server-side normalization added, and
  `db_name`/`redis_prefix` are derived from the slug so UI-created projects
  are fully provisioned.
- Doctor fixes found by failure-injection drills: scheduler heartbeat check
  no longer crashes when the cache store is down; Reverb health now probes
  the compose service hostname.

### Security

- Restore-drill hardening documented: `pg_restore --role` + database
  ownership pre-assignment (Postgres 15+ public schema) are required for a
  working restore.

## [0.1.0-rc.1] — 2026-09-26

First public release candidate of the **self-hosted backend & migration control plane**.

### Added

- **Platform core** — multi-project backend control plane: project registry,
  environments, health checks, database browser/SQL studio, auth management,
  API management, storage manager, functions runtime, realtime (Reverb),
  queues (Horizon), scheduled tasks, webhooks, secrets vault, team RBAC,
  audit log.
- **Migration Center** — import existing backends starting with Supabase:
  account connector, project import wizard, read-only source analysis
  (schema, RLS policies, RPC/functions, storage), migration planning,
  rehearsal against disposable targets, and guardrails (read-only sources,
  source≠target, no auto-migration).
- **AI Migration Copilot (BYOK)** — optional provider-agnostic copilot
  (OpenAI, Gemini, Anthropic, OpenRouter, OpenAI-compatible) for migration
  advisory, patch generation with human approval, and test/repair loops.
  Keys are encrypted at rest; nothing is sent to any provider unless the
  operator configures a key and invokes AI features.
- **Client SDKs** — JavaScript, PHP and Dart SDKs for deployed projects.
- **First-run setup wizard** (`/setup`) — system checks, platform identity,
  database/Redis/storage verification, first-owner bootstrap (no default
  credentials), domain/URL, optional mail and AI, security summary; locks
  itself after completion with race/replay protection.
- **Self-hosted Docker distribution** — production Compose stack (app,
  PostgreSQL 17, Redis, Horizon, scheduler, Reverb, Caddy), one application
  image serving all roles, internal-only databases, named-volume persistence,
  healthchecks, and an installer/`--check` mode plus an upgrader with
  pre-flight checks and pre-upgrade backup.
- **Release engineering** — SemVer via root `VERSION`, canonical version
  displayed in the console, distribution packaging with a private-reference
  scan gate, secret scan, dependency inventory (SBOM).

### Security

- No default credentials anywhere; strong password policy enforced
  platform-wide.
- PostgreSQL and Redis are never published to the host in production.
- Secrets (Supabase PATs, AI keys, project secrets, platform settings)
  encrypted at rest.
- Rate limiting on login, setup, API and AI endpoints; security headers at
  the edge; secure session cookies by default; no telemetry.

[0.1.0-rc.1]: https://example.invalid/releases/0.1.0-rc.1
