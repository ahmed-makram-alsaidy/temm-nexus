# TEMM Nexus v0.5.0 — Agent Runtime Platform (OpenCode)

**Release date:** 2026-10-04
**Status:** stable
**Minimum upgrade-from version:** 0.1.0 (additive migrations; existing 0.4.0 installations upgrade in place)

## What's new

0.5.0 introduces the **Agent Runtime Platform** — a second AI capability
alongside Nexus AI. Where Nexus AI assists operators, the **Developer
Agent** is an actual coding-agent runtime that works on a disposable copy
of a project and never touches authoritative source without an explicit
human approval.

- **Agent Runtime Platform** with a runtime-adapter architecture
  (`AgentRuntimeContract` + driver registry). First supported runtime:
  **OpenCode 1.18.34** (pinned; the adapter refuses incompatible server
  versions). Future runtimes (Codex CLI, Claude Code, ACP agents) slot in
  as new adapters — tasks, workspaces, approvals, audit, diff and
  verification are runtime-agnostic.
- **Developer Agent Web UI** — a first-class workbench: pick project,
  runtime and model, start a task, watch live activity, review the diff,
  approve, apply, verify.
- **Developer Agent CLI** — `agent:status`, `agent:runtimes`,
  `agent:models`, `agent:run`, `agent:show`, `agent:diff`,
  `agent:approve`, `agent:apply`, `agent:cancel`, `agent:verify`. The CLI
  and the web UI share the same service layer and the same persisted task
  state (a task created via CLI can be approved in the web UI and applied
  via CLI — or the reverse).
- **Managed and external runtimes** — run OpenCode as a TEMM-managed
  internal service (compose files included) or point TEMM at an
  independently operated OpenCode server. Both use the same adapter.
- **Live model discovery** — models are listed exactly as the runtime
  reports them (`provider/model` ids verbatim, context limits included).
  TEMM never invents pricing or "free" labels.
- **Isolated Git worktrees** — every task gets a unique disposable
  `git worktree` cut from the current source revision. The lifecycle is
  always: PLAN → **ISOLATED WORKSPACE** → DIFF → **HUMAN APPROVAL** →
  APPLY → VERIFY.
- **Real-time task activity** — a bounded event ledger and command ledger
  (commands are recorded as metadata: byte size and truncation state;
  output content is never stored), surfaced live in the web UI and CLI.
- **Diff review** — deterministic per-task changesets computed with plain
  `git diff --binary` from the isolated workspace, rendered in the web UI
  and printable via the CLI.
- **Fingerprint-bound approvals** — every changeset carries a SHA-256
  fingerprint. An approval binds to exactly one fingerprint and one base
  revision, is single-use, and is revalidated immediately before apply:
  moved source is refused as **STALE**; changed content is refused as
  **TAMPERED**.
- **Apply + verification lifecycle** — application uses
  repository-native `git apply` after a whole-patch dry run, records a
  recovery snapshot in the audit trail, and then runs the project's
  operator-defined verification policy (commands, exit codes, duration).
  Deploy is categorically outside Agent Runtime v1.
- **English + Arabic UI** — full parity, true RTL for Arabic, technical
  identifiers (model ids, hashes, paths, commands) kept LTR.
- **Complete audit trail** — runtime configuration, task lifecycle,
  approval decisions, applies (with a recovery snapshot hash) and
  verification results are recorded in the admin audit ledger.
- **Restart/persistence support** — tasks, approvals, audit records and
  runtime configuration live in the database and volume-backed storage;
  container recreation preserves them and in-progress tasks fail
  truthfully with structured error categories rather than faking
  completion.
- **New `agents.*` capability vocabulary** — view/run/configure/cancel/
  approve/apply/audit mapped across platform/workspace/project scopes;
  runtime configuration is platform-scope only.

## Security

- **Defense-in-depth per-task filesystem isolation.** TEMM assigns a
  unique Git worktree per task. OpenCode external-directory permissions
  restrict tool access to the assigned workspace. Managed shell
  executions are additionally constrained by a **fail-closed Landlock
  sandbox** (filesystem grants limited to the session workspace, its own
  worktree gitdir — with the repository object store read-only — system
  paths, /proc, /dev, /tmp; everything else is denied by default).
  Environment inheritance is scrubbed, so runtime credentials are
  invisible to sessions. The managed OpenCode service has **no Docker
  socket and no public endpoint**. Automatic production deployment does
  not exist in Agent Runtime v1.
- Approval replay, changeset tampering and stale-source applies are
  refused by construction and covered by automated tests.
- Secrets are stored encrypted at rest, are write-only in the UI, and a
  leakage scan over logs, audit records, ledgers and HTML is part of the
  test suite.

### Honest architecture boundary

Managed mode runs a **shared OpenCode runtime process**: filesystem and
tool boundaries are enforced per task (unique worktree, permission
ruleset, Landlock shell sandbox), but the server itself serves multiple
sessions from one container. Container-per-task isolation is a possible
future hardening layer. This release makes no absolute-sandbox claims.

## Changed

- Horizon gains a dedicated `agents` queue supervisor (long-timeout
  worker for coding sessions).
- The production application image now includes `git` (worktree
  operations run inside the app).
- The managed OpenCode image carries a complete, correctly-owned storage
  tree so a fresh shared volume is initialized correctly regardless of
  container start order.

## Upgrade notes

- Run `php artisan migrate --force` (the upgrade script does this).
- For the managed runtime, set in `.env`:
  `OPENCODE_SERVER_PASSWORD` (required — `./scripts/install.sh` generates
  it automatically on fresh installs; generate manually with
  `openssl rand -base64 24` when upgrading), optionally `OPENCODE_VERSION`
  (default 1.18.34) and provider keys (`ANTHROPIC_API_KEY`,
  `OPENROUTER_API_KEY`, `OPENAI_API_KEY`,
  `GOOGLE_GENERATIVE_AI_API_KEY`).
- Then `docker compose -f docker-compose.prod.yml up -d` to create the
  internal `opencode` service and configure the runtime under
  Settings → Developer Agents (mode "Managed") → Test connection.
- Upgrading from v0.5.0-rc.1 is a pure metadata change: no data or schema
  action is required beyond the normal migration step.

## Known limitations

- No production deployment capability — verification never deploys, and
  deployment is not reachable through the Agent Runtime.
- The managed OpenCode server is a shared runtime process (see the honest
  boundary note above).
- A project's verification policy is operator-defined in configuration;
  when a project defines no commands, verification is reported honestly
  as `skipped`, never as "passed".
- Free-tier model availability varies by provider; a model that returns
  empty replies surfaces as a truthful "completed without changes" task,
  never as a fake success.

## Verification

This release passed the following gates before publication:

- Full hermetic blocking suite: **823 tests, 4605 assertions, 0 failures,
  0 errors** (22 documented live-fixture skips), including the Agent
  Runtime security suites: SSRF posture, approval replay/tamper/stale
  refusals, cross-project isolation, oversized-changeset refusal,
  secret-leakage scans, workspace traversal/symlink/VCS guards.
- Localization completeness gates (English/Arabic parity, RTL, no raw
  keys) — green.
- Disposable-VPS dogfood across the 0.5.0 line: deployment via the
  supported upgrader, real OpenCode 1.18.34 tasks through the full
  lifecycle (CLI and web, including cross-interface operation), 3-task
  concurrency with mid-flight cancellation, restart/persistence,
  secret-leakage scans (0 findings), and Landlock isolation probes
  (sibling-workspace, cross-project, runtime-secret and app_storage reads
  all denied inside real model sessions).
- Artifact-only fresh install and an rc.1 → stable upgrade drill driven
  by the published artifact (see the verification summary in the release
  publication).
