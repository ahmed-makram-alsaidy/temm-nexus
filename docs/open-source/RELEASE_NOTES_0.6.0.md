# TEMM Nexus v0.6.0 — The Product Experience Release

**Release date:** 2026-10-07
**Status:** stable
**Upgrade-from:** v0.5.0 installs upgrade in place; the release adds two fully
additive migrations and performs no destructive schema change.

## What's new

0.6.0 redesigns how the platform feels to operate. Where earlier releases
accumulated capability, 0.6.0 turns it into one coherent product: light-first
surfaces, a navigation model built around Projects, a guided migration
journey, an assistant you talk to, and a coding agent you supervise.

- **Redesigned product experience** — the entire operator surface moves to
  the light-first 0.6 design system: one canvas, one typography scale, one
  accent and radius language across login, setup, console and every project
  surface. Dark mode remains a first-class preference; light stays the
  default.
- **Simplified Home / Projects navigation** — the console shell is now
  Home · Projects · Clients · Activity · Settings, with Nexus AI and the
  Developer Agent grouped under AI. Project context lives in tabs
  (Overview, Migration, Data, Access, Build, Operate, Settings), not in a
  sidebar that grows with every feature. Home is a calm command center that
  answers "what needs me today" with a derived attention list and a Continue
  action that always agrees with the underlying project state.
- **Guided New Project workflow** — a five-step wizard (Project, Source,
  Destination, Analyze, Review) with a source connection test before
  anything is stored, inline workspace creation, and analysis warnings
  classified in plain language. A source with zero tables can never report
  Ready.
- **Resume-safe wizard drafts** — New Project drafts are stored server-side
  and survive a refresh or a closed browser; you pick up exactly where you
  left off.
- **Unified Migration journey** — one stage model (Connect → Analyze → Plan
  → Migrate → Live Sync → Validate → Cutover) shared by the wizard, the
  project overview and the migration center, with honest per-stage states,
  bounded run history and a safe dry-run-first transfer flow.
- **Improved project status consistency** — Projects and their overview
  derive progress, source connection and readiness facts from real
  telemetry, never invented; the same stage model is used everywhere a
  project's migration state is shown.
- **Nexus AI chat-first** — the assistant is now a conversation: a
  chat-first surface with humanized tool activity instead of raw dumps,
  grounded answers with visible context, and provider failures presented as
  classified, actionable product errors. Safe actions still preview first
  and apply only after explicit approval.
- **Persistent AI conversations** — multi-turn history is durable per
  platform/workspace/project scope, so an assistant thread picks up where
  it left off.
- **Developer Agent workbench, status-first** — the coding-agent surface
  leads with human status (Queued → Working → Ready for review →
  Completed), a humanized timeline, and a decision-oriented diff view.
  Transport and runtime plumbing moved behind an Advanced disclosure in the
  settings.
- **Real managed OpenCode runtime** — OpenCode remains the first supported
  runtime (1.18.34 pinned; the adapter refuses incompatible servers). A
  managed runtime runs inside the platform's own stack with no operator
  endpoint configuration; external HTTPS runtimes remain fully supported.
- **Approval → apply → verify safety** — unchanged and re-verified: every
  task runs in a fresh disposable git worktree, produces a deterministic
  fingerprint-bound changeset, and never touches authoritative source until
  a human approves the exact diff. Approvals are single-use, validated
  against fingerprint and base revision, and followed by real verification
  checks (with only each check's result and duration retained).
- **English + Arabic / RTL** — complete RTL support across the redesigned
  surfaces, natural Arabic copy, isolated left-to-right rendering for
  technical values, and localized navigation and page titles everywhere.
- **Operator polish** — consistent statuses, errors and empty states: one
  status dictionary across pages, raw enum values and internal vocabulary
  removed from default surfaces, and every empty state explains what
  belongs here and what to do next. Raw diagnostics live only behind
  explicit Technical details disclosures.
- **Performance** — bounded, indexed queries on the operator surfaces; the
  control-plane studios and record browser stay responsive with real-world
  data volumes.
- **Upgrade compatibility** — existing v0.5.0 installations upgrade in
  place: the two new migrations are additive and preserve all existing
  workspaces, projects, repositories, activity and configuration.

## Upgrading from v0.5.0

1. Back up (the platform's `./scripts/upgrade.sh` does this as step one).
2. Replace the application source with this release.
3. Run the normal migrations — they are additive and preserve all existing
   workspaces, projects, repositories, activity and configuration.
4. Rebuild the app image and restart the stack.

Existing AI provider configurations, agent runtimes, projects and audit
history remain valid. No re-install or data repair is required. Upgrading
from v0.6.0-rc.1 is a pure metadata change: no data or schema action is
required beyond the normal migration step.

## Verification

This release passed the following gates before publication:

- Full hermetic blocking suite (SQLite, no network): **1028 tests, 6168
  assertions, 0 failures, 0 errors** (23 documented live-fixture skips),
  including the Agent Runtime security suites and the secret-leakage scans.
- Localization completeness gates (English/Arabic parity, RTL, no raw
  keys) — green.
- A real upgrade from v0.5.0 with seeded projects was performed against
  this exact code: both migrations applied cleanly and all data remained
  accessible.
- A fresh install from the packaged stable artifact was performed: setup,
  first owner creation, login and the Home surface all verified.
- The managed OpenCode lifecycle was exercised end to end during the
  release candidate cycle: runtime connect with version detection, real
  model discovery, a real coding task in an isolated worktree, human diff
  review, single-use approval, apply and post-apply verification — with
  authoritative source untouched before approval.

## Notes

- OpenCode 1.18.34 is the pinned, supported runtime version.
- No other runtimes (Codex CLI, Claude Code, ACP) are included in this
  release; their adapters land when their integrations mature.
